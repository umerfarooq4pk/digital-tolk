<?php

namespace DTApi\Http\Controllers;

use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use DTApi\Models\Job;
use DTApi\Models\Distance;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    protected $repository;

    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    public function index(Request $request)
    {
        $user_id = $request->get('user_id');
        $authUser = $request->__authenticatedUser;
        $response = $user_id ? $this->repository->getUsersJobs($user_id) : 
                               ($authUser->user_type == env('ADMIN_ROLE_ID') || $authUser->user_type == env('SUPERADMIN_ROLE_ID') ?
                               $this->repository->getAll($request) : null);

        return response($response);
    }

    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);
        return response($job);
    }

    public function store(Request $request)
    {
        $response = $this->repository->store($request->__authenticatedUser, $request->all());
        return response($response);
    }

    public function update($id, Request $request)
    {
        $response = $this->repository->updateJob($id, array_except($request->all(), ['_token', 'submit']), $request->__authenticatedUser);
        return response($response);
    }

    public function immediateJobEmail(Request $request)
    {
        $response = $this->repository->storeJobEmail($request->all());
        return response($response);
    }

    public function getHistory(Request $request)
    {
        $user_id = $request->get('user_id');
        $response = $user_id ? $this->repository->getUsersJobsHistory($user_id, $request) : null;
        return response($response);
    }

    public function acceptJob(Request $request)
    {
        $response = $this->repository->acceptJob($request->all(), $request->__authenticatedUser);
        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $response = $this->repository->acceptJobWithId($request->get('job_id'), $request->__authenticatedUser);
        return response($response);
    }

    public function cancelJob(Request $request)
    {
        $response = $this->repository->cancelJobAjax($request->all(), $request->__authenticatedUser);
        return response($response);
    }

    public function endJob(Request $request)
    {
        $response = $this->repository->endJob($request->all());
        return response($response);
    }

    public function customerNotCall(Request $request)
    {
        $response = $this->repository->customerNotCall($request->all());
        return response($response);
    }

    public function getPotentialJobs(Request $request)
    {
        $response = $this->repository->getPotentialJobs($request->__authenticatedUser);
        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'] ?? '';
        $session = $data['session_time'] ?? '';
        $flagged = ($data['flagged'] == 'true' && $data['admincomment'] != '') ? 'yes' : 'no';
        $manually_handled = ($data['manually_handled'] == 'true') ? 'yes' : 'no';
        $by_admin = ($data['by_admin'] == 'true') ? 'yes' : 'no';
        $admincomment = $data['admincomment'] ?? '';

        if ($time || $distance) {
            Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::where('id', '=', $jobid)->update(['admin_comments' => $admincomment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manually_handled, 'by_admin' => $by_admin]);
        }

        return response('Record updated!');
    }

    public function reopen(Request $request)
    {
        $response = $this->repository->reopen($request->all());
        return response($response);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');
        return response(['success' => 'Push sent']);
    }

    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }
}
