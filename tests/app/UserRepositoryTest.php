<?php
use PHPUnit\Framework\TestCase;
use DTApi\Repository\UserRepository;
use DTApi\Models\User;
use DTApi\Models\UserMeta;
use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Type;
use DTApi\Models\UsersBlacklist;
use Illuminate\Support\Facades\DB;

class UserRepositoryTest extends TestCase
{
    protected $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = new UserRepository(new User());
    }

    public function testCreateOrUpdateNewUser()
    {
        $request = [
            'role' => 1,
            'name' => 'John Doe',
            'company_id' => 1,
            'department_id' => 1,
            'email' => 'john@example.com',
            'dob_or_orgid' => '123456',
            'phone' => '1234567890',
            'mobile' => '9876543210',
            'password' => 'password',
            'consumer_type' => 'paid',
            'customer_type' => 'business',
        ];

        $user = $this->userRepository->createOrUpdate(null, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals($request['name'], $user->name);
        $this->assertEquals($request['email'], $user->email);
    }

    public function testCreateOrUpdateExistingUser()
    {
        // Create a user first
        $user = User::factory()->create();

        $request = [
            'role' => 2,
            'name' => 'Jane Smith',
            'company_id' => 2,
            'department_id' => 2,
            'email' => 'jane@example.com',
            'dob_or_orgid' => '654321',
            'phone' => '1234567890',
            'mobile' => '9876543210',
            'password' => 'newpassword',
            'consumer_type' => 'free',
            'customer_type' => 'personal',
        ];

        $updatedUser = $this->userRepository->createOrUpdate($user->id, $request);

        $this->assertInstanceOf(User::class, $updatedUser);
        $this->assertEquals($request['name'], $updatedUser->name);
        $this->assertEquals($request['email'], $updatedUser->email);
    }
}