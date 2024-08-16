<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator; // Import Validator
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected $key = 'your-secret-key'; // Replace with your actual secret key
    private $secretKey;


    public function __construct()
    {
        $this->secretKey = env('JWT_SECRET');
    }

    public function login(Request $request)
    {
        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'status' => false
            ], 422);
        }

        $credentials = $request->only('email', 'password');

        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Incorrect email or password',
                'status' => false
            ], 401);
        }

        // Get the role name from the roles table using the role_id
        $role = Role::where('id', $user->role_id)->first();
        $roleName = $role ? $role->name : 'N/A';
        $userGroup = $user->group;

        // Create JWT payload
        $payload = [
            'iat' => time(),
            'exp' => time() + 3600, // 1 hour expiration
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $roleName,
                'group' => $userGroup
            ]
        ];

        // Generate JWT token
        $token = JWT::encode($payload, $this->secretKey, 'HS256');

        return response()->json([
            'token' => $token,
            'message' => 'Login successful',
            'status' => true,
            'role' => $roleName, 'group' => $userGroup,
            'email' => $user->email
        ]);
    }

    public function createUser(Request $request)
    {
        // Log incoming request
        Log::info('Incoming request: ' . json_encode($request->all()));

        $rules = [
            'username' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|',
            'role_id' => 'required|',
        ];

        // Create a Validator instance and validate the request data
        $validator = Validator::make($request->all(), $rules);

        // Check if validation fails
        if ($validator->fails()) {
            // Return a JSON response with validation errors
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        $validatedData = $validator->validated();


        $name = $validatedData['username'];
        $email = $validatedData['email'];
        $password = Hash::make($validatedData['password']);
        $role_id = $validatedData['role_id'];

        try {
            // Insert user into database
            $authHeader = $request->header('Authorization');

            if (!$authHeader) {
                return response()->json(['status' => false, 'message' => 'Authorization header not found'], 401);
            }

            if (strpos($authHeader, 'Bearer ') !== 0) {
                return response()->json(['status' => false, 'message' => 'Malformed authorization header'], 400);
            }

            $token = str_replace('Bearer ', '', $authHeader);

            try {
                $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
            } catch (\Exception $e) {
                return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
            }
            if (!isset($decoded->data->group)) {
                return response()->json(['status' => false, 'message' => 'Provide a role'], 403);
            }

            $existingUser = DB::table('users')->where('email', $email)->first();

            if ($existingUser) {
                return response()->json(['status' => false, 'message' => 'Email already exists'], 400);
            }
            $userId = DB::table('users')->insertGetId([
                'username' => $name,
                'email' => $email,
                'password' => $password,
                'role_id' => $role_id,
                'group' => $decoded->data->group
            ]);

            // Fetch the user
            $user = DB::table('users')->where('id', $userId)->first();

            // Generate JWT
            $secretKey = env('JWT_SECRET');
            $payload = [
                'iat' => time(),
                'exp' => time() + (84600 * 60), // 1 hour expiration
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role_id' => $role_id,
                    'group' => $decoded->data->group
                ],
            ];
            $jwt = JWT::encode($payload, $secretKey, 'HS256');
            // Create password reset link
            $link = "http://localhost:3002/reset/password?token=$jwt";

            // Create email body
            $body = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Set Your Password</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            padding: 10px 0;
            border-bottom: 1px solid #dddddd;
        }
        .content {
            padding: 20px 0;
        }
        .footer {
            text-align: center;
            padding: 10px 0;
            border-top: 1px solid #dddddd;
            font-size: 12px;
            color: #999999;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            margin-top: 20px;
            font-size: 16px;
            color: #ffffff;
            background-color: #007bff;
            text-decoration: none;
            border-radius: 5px;
        }
        .button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Welcome to the Dispute Portal</h1>
        </div>
        <div class='content'>
            <p>Hello,</p>
            <p>An admin has created an account for you on our dispute portal. Please use the link below to set your password:</p>
            <p><a href='$link' class='button'>Set Your Password</a></p>
            <p>If you did not request this email, please ignore it.</p>
            <p>Your email: $email</p>
        </div>
        <div class='footer'>
            <p>&copy; Sterling Dispute Portal. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
";

            // Send email using cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.postmarkapp.com/email');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'From' => 'support@ringo.ng',
                'To' => $email,
                'Subject' => 'Dispute Portal Account Creation',
                'HtmlBody' => $body,
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Postmark-Server-Token: 604ccef6-7866-499c-9f25-674eed0dc35a',
                'Content-Type: application/json',
                'Accept: application/json',
            ]);

            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false) {
                Log::error('Postmark cURL error: ' . $error);
                return response()->json([
                    'status' => false,
                    'message' => 'Error sending email: ' . $error,
                ], 500);
            }

            // Return success response
            return response()->json([
                'status' => true,
                'message' => 'User created successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Error creating user: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteUser(Request $request, $id)
    {
        // $response = ['status' => false, 'message' => ''];

        // if ($request->getMethod() !== 'DELETE') {
        //     return response()->json(['status' => false, 'message' => 'Invalid request method']);
        // }

        Log::info($id);

        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json(['status' => false, 'message' => 'Authorization header not found'], 401);
        }

        if (strpos($authHeader, 'Bearer ') !== 0) {
            return response()->json(['status' => false, 'message' => 'Malformed authorization header'], 400);
        }

        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
        }

        Log::info('Incoming request to delete user', (array)$decoded);

        if (!isset($decoded->data->role) || $decoded->data->role !== 'super_admin') {
            return response()->json(['status' => false, 'message' => 'Permission denied'], 403);
        }

        $userId = $request->id;

        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'User ID not provided']);
        }

        Log::info('Incoming request to delete user ID: ' . $userId);

        try {
            $deleted = DB::table('users')->where('id', $userId)->delete();

            if ($deleted) {
                return response()->json(['status' => true, 'message' => 'User deleted successfully']);
            } else {
                return response()->json(['status' => false, 'message' => 'User not found or could not be deleted']);
            }
        } catch (\Exception $e) {
            Log::error('Database error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
        }
    }
    public function getUserById(Request $request, $id)
    {
        $response = ['status' => false, 'message' => '', 'data' => []];


        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json(['status' => false, 'message' => 'Authorization header not found'], 401);
        }

        if (strpos($authHeader, 'Bearer ') !== 0) {
            return response()->json(['status' => false, 'message' => 'Malformed authorization header'], 400);
        }

        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 401);
        }

        Log::info('Incoming request to fetch user by ID', (array)$decoded);

        try {
            $user = DB::table('users')->where('id', $id)->first();

            if ($user) {
                return response()->json(['status' => true, 'data' => $user]);
            } else {
                return response()->json(['status' => false, 'message' => 'User not found']);
            }
        } catch (\Exception $e) {
            Log::error("Database error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
        }
    }
    public function resetPassword(Request $request)
    {
        $response = ['status' => false, 'message' => ''];


        $requestData = $request->json()->all();
        Log::info('Incoming password reset request: ', $requestData);

        $token = $requestData['token'] ?? '';
        $newPassword = $requestData['password'] ?? '';
        $confirmPassword = $requestData['confirmPassword'] ?? '';

        if (empty($token) || empty($newPassword) || empty($confirmPassword)) {
            $response['message'] = 'All fields are required';
            Log::error('Error: ' . $response['message']);
            return response()->json($response);
        }

        if ($newPassword !== $confirmPassword) {
            $response['message'] = 'Passwords do not match';
            Log::error('Error: ' . $response['message']);
            return response()->json($response);
        }

        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));

            if (isset($decoded->data) && is_object($decoded->data) && isset($decoded->data->email)) {
                $email = $decoded->data->email;
                $passwordHash = Hash::make($newPassword);

                DB::table('users')->where('email', $email)->update(['password' => $passwordHash]);

                $response['status'] = true;
                $response['message'] = 'Password reset successfully';
                Log::info('Password reset successfully for email: ' . $email);
            } else {
                $response['message'] = 'Invalid token data';
                Log::error('Error: ' . $response['message']);
            }
        } catch (\Exception $e) {
            $response['message'] = 'Token validation failed: ' . $e->getMessage();
            Log::error('Token validation failed: ' . $e->getMessage());
        }

        return response()->json($response);
    }
    public function updateUser(Request $request)
    {

        Log::info('Incoming request: ', $request->all());

        // Check Authorization header
        $authHeader = $request->header('Authorization');
        if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
            return response()->json(["message" => "Authorization header not found or malformed"], 401);
        }

        // Extract and decode the JWT token
        $token = str_replace('Bearer ', '', $authHeader);
        Log::info('Incoming request: ', $request->all());
        try {

            try {
                $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
            } catch (\Exception $e) {
                return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
            }
            if (!isset($decoded->data->role) && $decoded->data->role !== 'super_admin' && $decoded->data->role !== 'admin') {
                return response()->json(['status' => false, 'message' => 'Permission denied'], 403);
            }
            $userId = $request->input('id');
            $name = $request->input('username');
            $email = $request->input('email');
            $roleId = $request->input('role_id');

            if (!$userId || !$name || !$email || !$roleId) {
                return response()->json(['status' => false, 'message' => 'Missing required fields'], 400);
            }

            // Update user in the database
            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'username' => $name,
                    'email' => $email,
                    'role_id' => $roleId
                ]);

            return response()->json(['status' => true, 'message' => 'User updated successfully']);
        } catch (\Exception $e) {
            Log::error('Token validation failed: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Token validation failed', 'error' => $e->getMessage()], 400);
        }
    }

    public function fetchUsers(Request $request)
    {
        $response = [
            'status' => false,
            'message' => '',
            'data' => []
        ];

        // Handle preflight requests (OPTIONS)
        if ($request->isMethod('options')) {
            return response()->json(['message' => 'Preflight request'], 200);
        }

        Log::info('Incoming request: ', $request->all());

        // Check Authorization header
        $authHeader = $request->header('Authorization');
        if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
            return response()->json(["message" => "Authorization header not found or malformed"], 401);
        }

        // Extract and decode the JWT token
        $token = str_replace('Bearer ', '', $authHeader);
        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));

            if (isset($decoded->data->id)) {
                $userId = $decoded->data->id;

                try {
                    $users = DB::table('users')
                        ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                        ->select('users.id', 'users.username', 'users.email', 'roles.name as role')
                        ->get();

                    if ($users->isNotEmpty()) {
                        return response()->json(['status' => true, 'data' => $users]);
                    } else {
                        return response()->json(['status' => false, 'message' => 'No users found']);
                    }
                } catch (\Exception $e) {
                    Log::error('Database error: ' . $e->getMessage());
                    return response()->json(['status' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
                }
            } else {
                return response()->json(['status' => false, 'message' => 'User ID not found in the decoded data.']);
            }
        } catch (\Exception $e) {
            Log::error('Token validation failed: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Token validation failed', 'error' => $e->getMessage()], 400);
        }
    }
}
