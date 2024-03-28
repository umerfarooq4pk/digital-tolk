<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->all();
            $usertype = 'translator';
        }

        foreach ($jobs as $jobitem) {
            if ($jobitem->immediate == 'yes') {
                $emergencyJobs[] = $jobitem;
            } else {
                $normalJobs[] = $jobitem;
            }
        }

        $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
            $item['usercheck'] = Job::checkParticularJob($user_id, $item);
        })->sortBy('due')->all();

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        if (isset($request->get('page'))) {
            $pagenum = $page;
        } else {
            $pagenum = "1";
        }
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()
                        ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                        ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                        ->orderBy('due', 'desc')
                        ->paginate(15);
            $usertype = 'customer';
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $usertype = 'translator';

            $jobs = $jobs_ids;
            $noramlJobs = $jobs_ids;
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $pagenum];
        }
    }

    public function store($user, $data)
    {
        // Initialize response array
        $response = [];

        // Check if the user is a customer
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            // Validate required fields
            $validationResult = $this->validateFields($data);
            if (!$validationResult['status']) {
                return $validationResult;
            }

            // Set default values for customer phone type and physical type
            $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
            $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

            // Handle immediate booking
            if ($data['immediate'] == 'yes') {
                $data = $this->handleImmediateBooking($data);
            } else {
                // Handle regular booking
                $data = $this->handleRegularBooking($data);
            }

            // Set job type based on consumer type
            $data['job_type'] = $this->getJobType($user);

            // Set additional data
            $data['b_created_at'] = now()->format('Y-m-d H:i:s');
            $data['will_expire_at'] = $this->calculateExpiration($data['due'], $data['b_created_at']);
            $data['by_admin'] = $data['by_admin'] ?? 'no';

            // Create job
            $job = $cuser->jobs()->create($data);

            // Prepare success response
            $response['status'] = 'success';
            $response['id'] = $job->id;
            $response['job_for'] = $this->getJobFor($job);
            $response['customer_town'] = $cuser->userMeta->city;
            $response['customer_type'] = $cuser->userMeta->customer_type;

        } else {
            // User is not a customer, return failure response
            $response['status'] = 'fail';
            $response['message'] = "Translator cannot create a booking.";
        }

        return $response;
    }

    private function validateFields($data)
    {
        // Check if required fields are present
        $requiredFields = ['from_language_id', 'immediate', 'duration'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return [
                    'status' => false,
                    'message' => 'Du måste fylla in alla fält',
                    'field_name' => $field,
                ];
            }
        }
        return ['status' => true];
    }

    private function handleImmediateBooking($data)
    {
        // Handle immediate booking
        $immediateTime = 5;
        $due = now()->addMinutes($immediateTime)->format('Y-m-d H:i:s');
        return [
            'due' => $due,
            'immediate' => 'yes',
            'customer_phone_type' => 'yes', // Assuming immediate bookings always have a phone contact
            'type' => 'immediate',
        ];
    }

    private function handleRegularBooking($data)
    {
        // Handle regular booking
        $due = Carbon::createFromFormat('m/d/Y H:i', $data['due_date'] . ' ' . $data['due_time']);
        if ($due->isPast()) {
            return [
                'status' => 'fail',
                'message' => "Can't create booking in the past",
            ];
        }
        return [
            'due' => $due->format('Y-m-d H:i:s'),
            'type' => 'regular',
        ];
    }

    private function getJobType($user)
    {
        // Determine job type based on consumer type
        switch ($user->userMeta->consumer_type) {
            case 'rwsconsumer':
                return 'rws';
            case 'ngo':
                return 'unpaid';
            case 'paid':
                return 'paid';
            default:
                return null;
        }
    }

    private function calculateExpiration($due, $createdAt)
    {
        // Calculate expiration time
        return TeHelper::willExpireAt($due, $createdAt);
    }

    private function getJobFor($job)
    {
        // Determine job_for based on job properties
        $jobFor = [];
        if ($job->gender) {
            $jobFor[] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }
        if ($job->certified) {
            switch ($job->certified) {
                case 'both':
                    $jobFor[] = 'normal';
                    $jobFor[] = 'certified';
                    break;
                case 'yes':
                    $jobFor[] = 'certified';
                    break;
                default:
                    $jobFor[] = $job->certified;
                    break;
            }
        }
        return $jobFor;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        // Extract data
        $userType = $data['user_type'];
        $jobId = $data['user_email_job_id'];
        $reference = $data['reference'] ?? '';

        // Find the job
        $job = Job::findOrFail($jobId);
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $reference;

        // Update job details based on provided or default values
        if (isset($data['address'])) {
            $user = $job->user()->first();
            $job->address = $data['address'] ?: $user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
            $job->town = $data['town'] ?: $user->userMeta->city;
        }

        // Save the job
        $job->save();

        // Determine email recipient and name
        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $job->user->name;
        } else {
            $email = $job->user->email;
            $name = $job->user->name;
        }

        // Compose email subject
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        // Prepare data for email
        $sendData = [
            'user' => $job->user,
            'job'  => $job
        ];

        // Send email
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        // Prepare response
        $response = [
            'type' => $userType,
            'job' => $job,
            'status' => 'success'
        ];

        // Fire event
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type
        ];

        // Extract due date and time
        $dueDate = explode(" ", $job->due);
        $data['due_date'] = $dueDate[0];
        $data['due_time'] = $dueDate[1];

        // Construct job_for array
        $jobFor = [];
        if ($job->gender != null) {
            $jobFor[] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }
        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $jobFor[] = 'Godkänd tolk';
                    $jobFor[] = 'Auktoriserad';
                    break;
                case 'yes':
                    $jobFor[] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $jobFor[] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $jobFor[] = 'Rättstolk';
                    break;
                default:
                    $jobFor[] = $job->certified;
            }
        }
        $data['job_for'] = $jobFor;

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        // Get current timestamp
        $completeddate = now();

        // Get job ID from post data
        $jobid = $post_data["job_id"];

        // Find job detail by ID
        $job_detail = Job::with('translatorJobRel')->findOrFail($jobid);

        // Calculate session time
        $start = date_create($job_detail->due);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $session_time = $diff->format('%h:%i:%s');

        // Update job details
        $job_detail->end_at = $completeddate;
        $job_detail->status = 'completed';
        $job_detail->session_time = $session_time;

        // Get user details
        $user = $job_detail->user()->first();
        $email = !empty($job_detail->user_email) ? $job_detail->user_email : $user->email;
        $name = $user->name;

        // Send email notification
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id;
        $data = [
            'user'         => $user,
            'job'          => $job_detail,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $this->sendSessionEmail($email, $name, $subject, $data);

        // Save job details
        $job_detail->save();

        // Find translator job relationship
        $tr = $job_detail->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

        // Fire event for session ended
        Event::fire(new SessionEnded($job_detail, ($post_data['userid'] == $job_detail->user_id) ? $tr->user_id : $job_detail->user_id));

        // Get translator details
        $translator_user = $tr->user()->first();
        $email = $translator_user->email;
        $name = $translator_user->name;

        // Send email notification to translator
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id;
        $data = [
            'user'         => $translator_user,
            'job'          => $job_detail,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $this->sendSessionEmail($email, $name, $subject, $data);

        // Update translator job relationship
        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    private function sendSessionEmail($email, $name, $subject, $data)
    {
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        // Get user meta information
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;

        // Determine job type based on translator type
        $job_type = match ($translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid', // Default to unpaid for unrecognized types
        };

        // Get user languages
        $languages = UserLanguages::where('user_id', '=', $user_id)->pluck('lang_id')->all();

        // Get user gender and translator level
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        // Get job IDs based on criteria
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);

        // Filter jobs based on translator town and other conditions
        foreach ($job_ids as $key => $job) {
            $job = Job::find($job->id);
            $job_user_id = $job->user_id;
            $check_town = Job::checkTowns($job_user_id, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$check_town) {
                unset($job_ids[$key]);
            }
        }

        // Convert job IDs to job objects
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);

        return $jobs;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $translator_array = [];
        $delpay_translator_array = [];

        // Get all active translators except the excluded user
        $translators = User::where('user_type', '2')
                        ->where('status', '1')
                        ->where('id', '!=', $exclude_user_id)
                        ->get();

        foreach ($translators as $translator) {
            if (!$this->isNeedToSendPush($translator->id)) continue;

            // Skip immediate jobs for translators who do not accept emergency jobs
            if ($data['immediate'] == 'yes' && TeHelper::getUsermeta($translator->id, 'not_get_emergency') == 'yes') continue;

            // Get potential jobs for the translator
            $potential_jobs = $this->getPotentialJobIdsWithUserId($translator->id);

            foreach ($potential_jobs as $potential_job) {
                if ($job->id == $potential_job->id) {
                    $job_for_translator = Job::assignedToPaticularTranslator($translator->id, $potential_job->id);
                    if ($job_for_translator == 'SpecificJob') {
                        $job_checker = Job::checkParticularJob($translator->id, $potential_job);
                        if ($job_checker != 'userCanNotAcceptJob') {
                            $target_array = $this->isNeedToDelayPush($translator->id) ? &$delpay_translator_array : &$translator_array;
                            $target_array[] = $translator;
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msg_contents = $data['immediate'] == 'no' ? "Ny bokning för {$data['language']} tolk {$data['duration']} min {$data['due']}" : "Ny akutbokning för {$data['language']} tolk {$data['duration']} min";
        $msg_text = ["en" => $msg_contents];

        $this->logPushInfo($job->id, $translator_array, $delpay_translator_array, $msg_text, $data);

        // Send push notifications to suitable translators
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true);
    }

    // Helper function to log push information
    private function logPushInfo($job_id, $translator_array, $delpay_translator_array, $msg_text, $data)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        $physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));

        // Determine message based on job type
        $message = $this->getMessageBasedOnJobType($job);

        // Log the message
        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            $status = $this->sendSMSToTranslator($translator, $message);
            Log::info("Send SMS to {$translator->email} ({$translator->mobile}), status: " . print_r($status, true));
        }

        return count($translators);
    }

    // Helper function to determine message based on job type
    private function getMessageBasedOnJobType($job)
    {
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : UserMeta::where('user_id', $job->user_id)->value('city');

        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            return trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));
        } elseif ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            return trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        } elseif ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            return trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        } else {
            return ''; // Edge case, should not happen
        }
    }

    // Helper function to send SMS to a translator
    private function sendSMSToTranslator($translator, $message)
    {
        return SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
    }

    /**
     * Check if the push notification needs to be delayed for the specified user.
     *
     * @param int $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }
        
        return TeHelper::getUsermeta($user_id, 'not_get_nighttime') === 'yes';
    }

    /**
     * Check if the push notification needs to be sent for the specified user.
     *
     * @param int $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        return TeHelper::getUsermeta($user_id, 'not_get_notification') !== 'yes';
    }

    /**
     * Send push notifications to specific users.
     *
     * @param array $users
     * @param int $job_id
     * @param array $data
     * @param array $msg_text
     * @param bool $is_need_delay
     * @return void
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') == 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $android_sound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'no' ? 'normal_booking' : 'emergency_booking';
        $ios_sound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        $fields = json_encode($fields);
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://onesignal.com/api/v1/notifications",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', $onesignalRestAuthKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * Get user tags string from an array of users.
     *
     * @param array $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        return implode(',', array_map(function ($user) {
            return "user_{$user->id}";
        }, $users));
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $job_type = $job->job_type;
        $translator_type = $this->getTranslatorType($job_type);
        
        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = $this->getTranslatorLevel($job->certified);
        
        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $blacklist);
        
        return $users;
    }

    /**
     * Get translator type based on job type.
     *
     * @param string $job_type
     * @return string
     */
    private function getTranslatorType($job_type)
    {
        switch ($job_type) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                return '';
        }
    }

    /**
     * Get translator level based on certification.
     *
     * @param string|null $certified
     * @return array
     */
    private function getTranslatorLevel($certified)
    {
        $translator_level = [];

        if (!empty($certified)) {
            switch ($certified) {
                case 'yes':
                case 'both':
                    $translator_level = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'];
                    break;
                case 'law':
                case 'n_law':
                    $translator_level[] = 'Certified with specialisation in law';
                    break;
                case 'health':
                case 'n_health':
                    $translator_level[] = 'Certified with specialisation in health care';
                    break;
                case 'normal':
                    $translator_level = ['Layman', 'Read Translation courses'];
                    break;
                default:
                    $translator_level = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
                    break;
            }
        }

        return $translator_level;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::findOrFail($id);
        $log_data = [];

        $current_translator = $this->getCurrentTranslator($job);
        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $log_data[] = $changeDue['log_data'];
        }

        $langChanged = $this->changeLanguage($job, $data);
        if ($langChanged) {
            $log_data[] = $this->getLanguageLogData($job->from_language_id, $data['from_language_id']);
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logJobUpdate($cuser, $id, $log_data);

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        }

        $job->save();
        $this->handleNotifications($job, $changeDue, $changeTranslator, $langChanged);

        return ['Updated'];
    }

    /**
     * Get the current translator assigned to the job.
     *
     * @param \App\Models\Job $job
     * @return \App\Models\TranslatorJob|null
     */
    private function getCurrentTranslator($job)
    {
        return $job->translatorJobRel->where('cancel_at', null)->first() ?? $job->translatorJobRel->where('completed_at', '!=', null)->first();
    }

    /**
     * Log the job update action.
     *
     * @param \App\Models\User $cuser
     * @param int $id
     * @param array $log_data
     * @return void
     */
    private function logJobUpdate($cuser, $id, $log_data)
    {
        $log_message = 'USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ';
        $this->logger->addInfo($log_message, $log_data);
    }

    /**
     * Handle notifications for the updated job.
     *
     * @param \App\Models\Job $job
     * @param array $changeDue
     * @param array $changeTranslator
     * @param bool $langChanged
     * @return void
     */
    private function handleNotifications($job, $changeDue, $changeTranslator, $langChanged)
    {
        if ($changeDue['dateChanged']) {
            $this->sendChangedDateNotification($job, $changeDue['old_time']);
        }
        if ($changeTranslator['translatorChanged']) {
            $this->sendChangedTranslatorNotification($job, $changeTranslator['current_translator'], $changeTranslator['new_translator']);
        }
        if ($langChanged) {
            $this->sendChangedLangNotification($job, $job->from_language_id);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;

        if ($old_status !== $data['status']) {
            switch ($old_status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                return ['statusChanged' => true, 'log_data' => $log_data];
            }
        }

        return ['statusChanged' => false];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $this->resetJobDetails($job);
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->sendEmailAndNotification($job, $email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->sendEmailAndNotification($job, $email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * Reset job details when changing status to pending.
     *
     * @param Job $job
     */
    private function resetJobDetails(Job $job)
    {
        $job->created_at = now();
        $job->emailsent = 0;
        $job->emailsenttovirpal = 0;
        $job->save();
    }

    /**
     * Send email and notification.
     *
     * @param Job $job
     * @param string $email
     * @param string $name
     * @param string $subject
     * @param string $template
     * @param array $data
     */
    private function sendEmailAndNotification(Job $job, string $email, string $name, string $subject, string $template, array $data)
    {
        $this->mailer->send($email, $name, $subject, $template, $data);
        $this->sendNotificationTranslator($job, $this->jobToData($job), '*');
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout' && $data['admin_comments'] !== '') {
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return $data['status'] == 'timedout' && $data['admin_comments'] !== '';
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        
        if ($data['admin_comments'] === '') {
            return false;
        }
        
        $job->admin_comments = $data['admin_comments'];
        
        if ($data['status'] == 'completed' && $data['session_time'] !== '') {
            $interval = $data['session_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $user = $job->user()->first();
            $email = $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
            
            $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
            if ($translator) {
                $email = $translator->user->email;
                $name = $translator->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail['for_text'] = 'lön';
                $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
            }
        }
        
        $job->save();
        
        return $data['admin_comments'] !== '' && ($data['status'] != 'completed' || $data['session_time'] !== '');
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = [];
        $data['notification_type'] = 'session_start_remind';
        
        $due_explode = explode(' ', $due);
        $location_type = $job->customer_physical_type == 'yes' ? 'på plats i ' . $job->town : 'telefon';
        
        $msg_text = [
            "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (' . $location_type . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $delay_push = $this->bookingRepository->isNeedToDelayPush($user->id);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $delay_push);
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        $validStatuses = ['timedout'];

        if (in_array($data['status'], $validStatuses)) {
            $job->status = $data['status'];
            if ($data['admin_comments'] !== '') {
                $job->admin_comments = $data['admin_comments'];
                $job->save();
                return true;
            }
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        $validStatuses = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

        if (in_array($data['status'], $validStatuses)) {
            $job->status = $data['status'];
            if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $this->sendWithdrawalNotifications($job);
            }

            $job->save();
            return true;
        }
        return false;
    }

    private function sendWithdrawalNotifications($job)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $email = $translator->user->email;
        $name = $translator->user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                $new_translator = $this->createNewTranslator($current_translator, $data);
                $this->cancelCurrentTranslator($current_translator);
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                $new_translator = $this->createNewTranslator(null, $data, $job);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }

            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged];
    }

    private function createNewTranslator($current_translator, $data, $job = null)
    {
        if ($data['translator_email'] != '') {
            $translatorUserId = User::where('email', $data['translator_email'])->first()->id;
            $data['translator'] = $translatorUserId;
        }

        $translatorData = $current_translator ? $current_translator->toArray() : [];
        $translatorData['user_id'] = $data['translator'];
        if ($current_translator) {
            unset($translatorData['id']);
        }
        if ($job) {
            $translatorData['job_id'] = $job->id;
        }

        return Translator::create($translatorData);
    }

    private function cancelCurrentTranslator($current_translator)
    {
        $current_translator->cancel_at = Carbon::now();
        $current_translator->save();
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = $old_due != $new_due;
        $log_data = [];

        if ($dateChanged) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
        }

        return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;
        $data = ['user' => $user, 'job' => $job];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data['user'] = $translator;
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        
        // Prepare job data for sending push notification
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type
        ];

        // Extract due date and time
        $due_Date = explode(" ", $job->due);
        $data['due_date'] = $due_Date[0];
        $data['due_time'] = $due_Date[1];

        // Determine job for
        $data['job_for'] = [];
        if ($job->gender != null) {
            $data['job_for'][] = ucfirst($job->gender); // Capitalize gender
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Normal';
                $data['job_for'][] = 'Certified';
            } else {
                $data['job_for'][] = ucfirst($job->certified); // Capitalize certified
            }
        }

        // Send notification to suitable translators
        $this->sendNotificationTranslator($job, $data, '*');
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind'
        ];

        $msg_text = [
            "en" => 'Du har nu fått ' . ($job->customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen') . ' för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = collect($users)->map(function ($oneUser) {
            return [
                'key' => 'email',
                'relation' => '=',
                'value' => strtolower($oneUser->email)
            ];
        })->toArray();

        return json_encode($user_tags);
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->first();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = ['user' => $user, 'job' => $job];
                $this->sendJobAcceptedEmail($email, $name, $subject, $data);
            }
            // Add flash message here
            $jobs = $this->getPotentialJobs($cuser);
            $response = [
                'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'status' => 'success'
            ];
        } else {
            $response = [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        return $response;
    }

    private function sendJobAcceptedEmail($email, $name, $subject, $data)
    {
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->first();
                $this->sendJobAcceptedEmail($user, $job);

                $this->sendJobAcceptedNotification($user, $job);

                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . ' tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }

    private function sendJobAcceptedEmail($user, $job)
    {
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }

    private function sendJobAcceptedNotification($user, $job)
    {
        $data = [
            'notification_type' => 'job_accepted',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'duration' => $job->duration,
            'due' => $job->due
        ];
        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $msg_text = ["en" => 'Din bokning för ' . $data['language'] . ' translators, ' . $data['duration'] . 'min, ' . $data['due'] . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $this->processCustomerCancellation($job, $translator, $response);
        } else {
            $this->processTranslatorCancellation($job, $translator, $response);
        }

        return $response;
    }

    private function processCustomerCancellation($job, $translator, &$response)
    {
        $job->withdraw_at = Carbon::now();
        if ($job->withdraw_at->diffInHours($job->due) >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }
        $job->save();
        Event::fire(new JobWasCanceled($job));
        $response['status'] = 'success';
        $response['jobstatus'] = 'success';

        if ($translator) {
            $this->sendTranslatorCancellationNotification($translator, $job);
        }
    }

    private function processTranslatorCancellation($job, $translator, &$response)
    {
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $customer = $job->user()->first();
            if ($customer) {
                $this->sendCustomerCancellationNotification($customer, $job);
            }
            $job->status = 'pending';
            $job->created_at = now()->format('Y-m-d H:i:s');
            $job->will_expire_at = TeHelper::willExpireAt($job->due, now()->format('Y-m-d H:i:s'));
            $job->save();
            Job::deleteTranslatorJobRel($translator->id, $job->id);
            $this->sendNotificationToTranslators($job);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
        }
    }

    private function sendTranslatorCancellationNotification($translator, $job)
    {
        $data = [
            'notification_type' => 'job_cancelled',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'duration' => $job->duration,
            'due' => $job->due
        ];
        $msg_text = ["en" => 'Kunden har avbokat bokningen för ' . $data['language'] . 'tolk, ' . $data['duration'] . 'min, ' . $data['due'] . '. Var god och kolla dina tidigare bokningar för detaljer.'];
        if ($this->isNeedToSendPush($translator->id)) {
            $users_array = [$translator];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
        }
    }

    private function sendCustomerCancellationNotification($customer, $job)
    {
        $data = [
            'notification_type' => 'job_cancelled',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'duration' => $job->duration,
            'due' => $job->due
        ];
        $msg_text = ["en" => 'Er ' . $data['language'] . 'tolk, ' . $data['duration'] . 'min ' . $data['due'] . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'];
        if ($this->isNeedToSendPush($customer->id)) {
            $users_array = [$customer];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
        }
    }

    private function sendNotificationToTranslators($job)
    {
        $data = $this->jobToData($job);
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all suitable translators
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuserMeta = $cuser->userMeta;
        $jobType = $this->determineJobType($cuserMeta->translator_type);

        $userLanguages = $cuser->userLanguages->pluck('lang_id')->all();
        $gender = $cuserMeta->gender;
        $translatorLevel = $cuserMeta->translator_level;

        $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $userLanguages, $gender, $translatorLevel);

        foreach ($jobIds as $key => $job) {
            $specificJob = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $checkParticularJob = Job::checkParticularJob($cuser->id, $job);
            $checkTown = Job::checkTowns($job->user_id, $cuser->id);

            if ($specificJob === 'SpecificJob' && $checkParticularJob === 'userCanNotAcceptJob') {
                unset($jobIds[$key]);
            }

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '') && $job->customer_physical_type === 'yes' && !$checkTown) {
                unset($jobIds[$key]);
            }
        }

        return $jobIds;
    }

    private function determineJobType($translatorType)
    {
        switch ($translatorType) {
            case 'professional':
                return 'paid'; // Show all jobs for professionals.
            case 'rwstranslator':
                return 'rws'; // For rwstranslator only show rws jobs.
            case 'volunteer':
            default:
                return 'unpaid'; // For volunteers only show unpaid jobs.
        }
    }

    public function endJob($postData)
    {
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        // If the job is not in 'started' status, return success
        if ($jobDetail->status !== 'started') {
            return ['status' => 'success'];
        }

        $completedDate = now();
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $job = $jobDetail;
        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $interval;
        $job->save();

        $user = $job->user;
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $sessionTime = $diff->format('%h tim %i min');
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $this->sendSessionEndedEmail($email, $name, $subject, $data);

        $translatorRel = $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();
        $translator = $translatorRel->user;

        $email = $translator->email;
        $name = $translator->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $data['for_text'] = 'lön';
        $this->sendSessionEndedEmail($email, $name, $subject, $data);

        $translatorRel->update([
            'completed_at' => $completedDate,
            'completed_by' => $postData['user_id']
        ]);

        Event::fire(new SessionEnded($job, ($postData['user_id'] == $job->user_id) ? $translator->id : $job->user_id));

        return ['status' => 'success'];
    }

    private function sendSessionEndedEmail($email, $name, $subject, $data)
    {
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }


    public function customerNotCall($postData)
    {
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        $completedDate = now();
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $job = $jobDetail;
        $job->end_at = $completedDate;
        $job->status = 'not_carried_out_customer';
        $job->save();

        $translatorRel = $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();
        $translatorRel->update([
            'completed_at' => $completedDate,
            'completed_by' => $translatorRel->user_id
        ]);

        return ['status' => 'success'];
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs->superadminFilters($requestdata);
        } else {
            $allJobs->consumerFilters($requestdata, $consumer_type);
        }

        $allJobs->orderBy('created_at', 'desc')
                ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }

        return $allJobs;
    }

    private function superadminFilters(&$allJobs, $requestdata)
    {
        $allJobs->filterById($requestdata)
                ->filterByLanguage($requestdata)
                ->filterByStatus($requestdata)
                ->filterByExpiredAt($requestdata)
                ->filterByWillExpireAt($requestdata)
                ->filterByCustomerEmail($requestdata)
                ->filterByTranslatorEmail($requestdata)
                ->filterByTimeType($requestdata)
                ->filterByJobType($requestdata)
                ->filterByPhysical($requestdata)
                ->filterByPhone($requestdata)
                ->filterByFlagged($requestdata)
                ->filterByDistance($requestdata)
                ->filterBySalary($requestdata)
                ->filterByCount($requestdata)
                ->filterByConsumerType($requestdata)
                ->filterByBookingType($requestdata)
                ->filterByFeedback($requestdata);

        return $allJobs;
    }

    private function consumerFilters(&$allJobs, $requestdata, $consumerType)
    {
        $allJobs->filterById($requestdata)
                ->filterByLanguage($requestdata)
                ->filterByStatus($requestdata)
                ->filterByJobType($requestdata)
                ->filterByCustomerEmail($requestdata, $consumerType)
                ->filterByTimeType($requestdata)
                ->filterByDue($requestdata)
                ->filterByCreatedAt($requestdata)
                ->filterByFeedback($requestdata);

        return $allJobs;
    }

    private function filterById($requestdata)
    {
        return $this->whereIn('id', Arr::wrap($requestdata['id'] ?? []));
    }

    private function filterByLanguage($requestdata)
    {
        return $this->when(isset($requestdata['lang']), function ($query) use ($requestdata) {
            return $query->whereIn('from_language_id', Arr::wrap($requestdata['lang']));
        });
    }

    private function filterByStatus($requestdata)
    {
        return $this->when(isset($requestdata['status']), function ($query) use ($requestdata) {
            return $query->whereIn('status', Arr::wrap($requestdata['status']));
        });
    }

    private function filterByExpiredAt($requestdata)
    {
        return $this->when(isset($requestdata['expired_at']), function ($query) use ($requestdata) {
            return $query->where('expired_at', '>=', $requestdata['expired_at']);
        });
    }

    private function filterByWillExpireAt($requestdata)
    {
        return $this->when(isset($requestdata['will_expire_at']), function ($query) use ($requestdata) {
            return $query->where('will_expire_at', '>=', $requestdata['will_expire_at']);
        });
    }

    private function filterByCustomerEmail($requestdata, $consumerType)
    {
        return $this->when(isset($requestdata['customer_email']), function ($query) use ($requestdata, $consumerType) {
            $emailField = ($consumerType == 'RWS') ? 'rws_email' : 'email';
            return $query->whereHas('user', function ($subquery) use ($requestdata, $emailField) {
                $subquery->whereIn($emailField, Arr::wrap($requestdata['customer_email']));
            });
        });
    }

    private function filterByTranslatorEmail($requestdata)
    {
        return $this->when(isset($requestdata['translator_email']), function ($query) use ($requestdata) {
            return $query->whereHas('translatorJobRel', function ($subquery) use ($requestdata) {
                $subquery->whereIn('user_id', DB::table('users')->whereIn('email', $requestdata['translator_email'])->pluck('id'));
            });
        });
    }

    private function filterByTimeType($requestdata)
    {
        return $this->when(isset($requestdata['filter_timetype']), function ($query) use ($requestdata) {
            if ($requestdata['filter_timetype'] == 'created') {
                return $query->filterByCreatedAt($requestdata);
            } elseif ($requestdata['filter_timetype'] == 'due') {
                return $query->filterByDue($requestdata);
            }
        });
    }

    private function filterByCreatedAt($requestdata)
    {
        return $this->when(isset($requestdata['from']), function ($query) use ($requestdata) {
            return $query->where('created_at', '>=', $requestdata['from'])
                        ->when(isset($requestdata['to']), function ($query) use ($requestdata) {
                            $to = $requestdata['to'] . ' 23:59:00';
                            return $query->where('created_at', '<=', $to);
                        });
        });
    }

    private function filterByDue($requestdata)
    {
        return $this->when(isset($requestdata['from']), function ($query) use ($requestdata) {
            return $query->where('due', '>=', $requestdata['from'])
                        ->when(isset($requestdata['to']), function ($query) use ($requestdata) {
                            $to = $requestdata['to'] . ' 23:59:00';
                            return $query->where('due', '<=', $to);
                        });
        });
    }

    private function filterByJobType($requestdata)
    {
        return $this->when(isset($requestdata['job_type']), function ($query) use ($requestdata) {
            return $query->whereIn('job_type', Arr::wrap($requestdata['job_type']));
        });
    }

    private function filterByPhysical($requestdata)
    {
        return $this->when(isset($requestdata['physical']), function ($query) use ($requestdata) {
            return $query->where('customer_physical_type', $requestdata['physical'])
                        ->where('ignore_physical', 0);
        });
    }

    private function filterByPhone($requestdata)
    {
        return $this->when(isset($requestdata['phone']), function ($query) use ($requestdata) {
            return $query->where('customer_phone_type', $requestdata['phone'])
                        ->when(isset($requestdata['physical']), function ($query) use ($requestdata) {
                            return $query->where('ignore_physical_phone', 0);
                        });
        });
    }

    private function filterByFlagged($requestdata)
    {
        return $this->when(isset($requestdata['flagged']), function ($query) use ($requestdata) {
            return $query->where('flagged', $requestdata['flagged'])
                        ->where('ignore_flagged', 0);
        });
    }

    private function filterByDistance($requestdata)
    {
        return $this->when(isset($requestdata['distance']) && $requestdata['distance'] === 'empty', function ($query) {
            return $query->whereDoesntHave('distance');
        });
    }

    private function filterBySalary($requestdata)
    {
        return $this->when(isset($requestdata['salary']) && $requestdata['salary'] === 'yes', function ($query) {
            return $query->whereDoesntHave('user.salaries');
        });
    }

    private function filterByCount($requestdata)
    {
        return $this->when(isset($requestdata['count']) && $requestdata['count'] === 'true', function ($query) {
            return $query->count();
        });
    }

    private function filterByConsumerType($requestdata)
    {
        return $this->when(isset($requestdata['consumer_type']), function ($query) use ($requestdata) {
            return $query->whereHas('user.userMeta', function ($subquery) use ($requestdata) {
                $subquery->where('consumer_type', $requestdata['consumer_type']);
            });
        });
    }

    private function filterByBookingType($requestdata)
    {
        return $this->when(isset($requestdata['booking_type']), function ($query) use ($requestdata) {
            return $query->when($requestdata['booking_type'] === 'physical', function ($query) {
                return $query->where('customer_physical_type', 'yes');
            })
            ->when($requestdata['booking_type'] === 'phone', function ($query) {
                return $query->where('customer_phone_type', 'yes');
            });
        });
    }

    private function filterByFeedback($requestdata)
    {
        return $this->when(isset($requestdata['feedback']) && $requestdata['feedback'] === 'false', function ($query) {
            return $query->where('ignore_feedback', 0)
                        ->whereHas('feedback', function ($q) {
                            $q->where('rating', '<=', 3);
                        });
        });
    }

    public function alerts()
    {
        $jobs = Job::all();

        $sesJobs = $jobs->filter(function ($job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                return $diff >= $job->duration && $diff >= $job->duration * 2;
            }
            return false;
        });

        $jobIds = $sesJobs->pluck('id')->all();

        $languages = Language::where('active', '1')->orderBy('language')->get();

        $requestdata = request()->all();
        $all_customers = User::where('user_type', '1')->pluck('email')->all();
        $all_translators = User::where('user_type', '2')->pluck('email')->all();

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobIds)
                ->where('jobs.ignore', 0);

            $allJobs = $this->applyFilters($allJobs, $requestdata);

            $allJobs->select('jobs.*', 'languages.language')
                ->whereIn('jobs.id', $jobIds)
                ->orderBy('jobs.created_at', 'desc');

            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    private function applyFilters($query, $requestdata)
    {
        return $query->when(isset($requestdata['lang']), function ($query) use ($requestdata) {
                return $query->whereIn('jobs.from_language_id', Arr::wrap($requestdata['lang']));
            })
            ->when(isset($requestdata['status']), function ($query) use ($requestdata) {
                return $query->whereIn('jobs.status', Arr::wrap($requestdata['status']));
            })
            ->when(isset($requestdata['customer_email']), function ($query) use ($requestdata) {
                return $query->whereHas('user', function ($subquery) use ($requestdata) {
                    $subquery->where('email', $requestdata['customer_email']);
                });
            })
            ->when(isset($requestdata['translator_email']), function ($query) use ($requestdata) {
                return $query->whereHas('translatorJobRel.user', function ($subquery) use ($requestdata) {
                    $subquery->where('email', $requestdata['translator_email']);
                });
            })
            ->when(isset($requestdata['filter_timetype']), function ($query) use ($requestdata) {
                if ($requestdata['filter_timetype'] == "created") {
                    return $query->when(isset($requestdata['from']), function ($query) use ($requestdata) {
                            return $query->where('jobs.created_at', '>=', $requestdata["from"]);
                        })
                        ->when(isset($requestdata['to']), function ($query) use ($requestdata) {
                            $to = $requestdata["to"] . " 23:59:00";
                            return $query->where('jobs.created_at', '<=', $to);
                        });
                } elseif ($requestdata['filter_timetype'] == "due") {
                    return $query->when(isset($requestdata['from']), function ($query) use ($requestdata) {
                            return $query->where('jobs.due', '>=', $requestdata["from"]);
                        })
                        ->when(isset($requestdata['to']), function ($query) use ($requestdata) {
                            $to = $requestdata["to"] . " 23:59:00";
                            return $query->where('jobs.due', '<=', $to);
                        });
                }
            })
            ->when(isset($requestdata['job_type']), function ($query) use ($requestdata) {
                return $query->whereIn('jobs.job_type', Arr::wrap($requestdata['job_type']));
            });
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
        return compact('throttles');
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email');
        $all_translators = User::where('user_type', '2')->pluck('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = Job::query()
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0)
                ->where('jobs.status', 'pending')
                ->where('jobs.due', '>=', Carbon::now());

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status']);
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = User::where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', $user->id);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = User::where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = TranslatorJobRel::where('user_id', $user->id)->pluck('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }
            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type']);
            }
            $allJobs->select('jobs.*', 'languages.language');

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return compact('allJobs', 'languages', 'all_customers', 'all_translators', 'requestdata');
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        if (!$job) {
            return ['error' => 'Job not found'];
        }
        $job->ignore = 1;
        $job->save();
        return ['success' => 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        if (!$job) {
            return ['error' => 'Job not found'];
        }
        $job->ignore_expired = 1;
        $job->save();
        return ['success' => 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        if (!$throttle) {
            return ['error' => 'Job not found'];
        }
        $throttle->ignore = 1;
        $throttle->save();
        return ['success' => 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);

        if (!$job) {
            return ["error" => "Job not found"];
        }

        $jobData = $job->toArray();
        $now = Carbon::now();

        $datareopen = [
            'status' => 'pending',
            'created_at' => $now,
            'will_expire_at' => TeHelper::willExpireAt($jobData['due'], $now),
        ];

        if ($jobData['status'] != 'timedout') {
            Job::where('id', $jobid)->update($datareopen);
            $newJobId = $jobid;
        } else {
            $jobData['status'] = 'pending';
            $jobData['created_at'] = $now;
            $jobData['updated_at'] = $now;
            $jobData['will_expire_at'] = TeHelper::willExpireAt($jobData['due'], $now);
            $jobData['cust_16_hour_email'] = 0;
            $jobData['cust_48_hour_email'] = 0;
            $jobData['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            
            $newJob = Job::create($jobData);
            $newJobId = $newJob->id;
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $now]);
        Translator::create([
            'status' => 'cancelled',
            'created_at' => $now,
            'will_expire_at' => TeHelper::willExpireAt($jobData['due'], $now),
            'updated_at' => $now,
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => $now,
        ]);

        $this->sendNotificationByAdminCancelJob($newJobId);

        return ["success" => "Job reopened!"];
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        }elseif ($time === 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;

        return sprintf($format, $hours, $minutes);
    }

}