<?php

namespace App\Http\Controllers;

// use App\Http\Helpers\CloudinaryHelper;
use App\Http\Controllers\Helpers\CloudinaryHelper;
use App\Models\DisputeCategory;
use App\Models\DisputeSubcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class DisputeController extends Controller
{
    public function getCategoriesAndSubcategories(Request $request)
    {
        // Log the incoming request
        Log::info('Incoming request: ' . json_encode($request->all()));

        // Check if the request method is GET
        if ($request->method() !== 'GET') {
            return response()->json(['status' => false, 'message' => 'Invalid request method'], 405);
        }

        // Check for authorization header
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json(["message" => "Authorization header not found", 'status' => false], 401);
        }

        // Ensure the header starts with "Bearer "
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return response()->json(["message" => "Malformed authorization header", 'status' => false], 400);
        }

        // Extract the token from the header
        $token = str_replace('Bearer ', '', $authHeader);

        try {
            // Decode JWT token
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));

            // Fetch categories and subcategories from the database
            $categories = DB::table('dispute_categories')->select('id', 'name')->get();
            $subCategories = DB::table('dispute_subcategories')->select('id', 'name', 'category_id')->get();

            return response()->json(['status' => true, 'categories' => $categories, 'subCategories' => $subCategories]);
        } catch (\Exception $e) {
            Log::error('Error decoding JWT: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }
    }

    public function store(Request $request)
    {
        try {
            // Validate the incoming request
            $validatedData = $request->validate([
                'title' => 'required|string|max:255',  // Add title validation
                'category_name' => 'required|string',
                'sub_category_name' => 'required|string',
                'description' => 'required|string',
                'start_time' => 'required|date',
                'end_time' => 'required|date',
                'file' => 'nullable|file'
            ]);

            $authHeader = $request->header('Authorization');
            if (!$authHeader) {
                return response()->json(["message" => "Authorization header not found", 'status' => false], 401);
            }

            if (strpos($authHeader, 'Bearer ') !== 0) {
                return response()->json(["message" => "Malformed authorization header", 'status' => false], 400);
            }

            $token = str_replace('Bearer ', '', $authHeader);

            try {
                $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
                $userId = $decoded->data->id ?? null;
                if (!$userId) {
                    return response()->json(["message" => "Invalid token", 'status' => false], 401);
                }
            } catch (\Exception $e) {
                Log::error("JWT decoding error: " . $e->getMessage());
                return response()->json(["message" => "Unauthorized", 'status' => false], 401);
            }

            // Generate a unique tracking ID
            $trackingId = 'DIS-' . strtoupper(bin2hex(random_bytes(6)));

            $userId = $decoded->data->id;
            $title = $validatedData['title'];  // Add title
            $categoryName = $validatedData['category_name'];
            $subCategoryName = $validatedData['sub_category_name'];
            $description = $validatedData['description'];
            $startTime = $validatedData['start_time'];
            $endTime = $validatedData['end_time'];

            // Find or create the category
            $category = DisputeCategory::firstOrCreate(['name' => $categoryName]);

            // Find or create the subcategory
            $subCategory = DisputeSubcategory::firstOrCreate(
                ['name' => $subCategoryName, 'category_id' => $category->id]
            );

            // Insert dispute data into the database
            $disputeId = DB::table('disputes')->insertGetId([
                'user_id' => $userId,
                'title' => $title,  // Insert the title
                'category_id' => $category->id,
                'subcategory_id' => $subCategory->id,
                'description' => $description,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'tracking_id' => $trackingId,  // Add the tracking ID
                'created_at' => now(),
                'updated_at' => now(),
                'status' => 'pending'
            ]);

            // Handle file upload if present
            $fileUrl = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $filename = $file->getClientOriginalName();
                $path = $file->storeAs('public/dispute_files', $filename);
                $fileUrl = asset(str_replace('public/', 'storage/', $path));
                $filePath = Cloudinary::uploadFile($request->file('file')->getRealPath())->getSecurePath();
                Log::info($filePath);

                // Save file data into the dispute_files table
                DB::table('dispute_files')->insert([
                    'dispute_id' => $disputeId,
                    'file_path' => $filePath,
                    'public_folder_link' => $fileUrl,  // Store the public link
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                Log::info("File uploaded successfully: $fileUrl for dispute ID $disputeId");
            }

            // Send email notification to the client
            $clientEmail = 'emsthias33@gmail.com'; // Assuming you have the client's email in the JWT
            $this->sendDisputeCreatedEmail($clientEmail, $trackingId);

            return response()->json([
                'status' => true,
                'message' => 'Dispute created successfully',
                'data' => [
                    'dispute_id' => $disputeId,
                    'tracking_id' => $trackingId,
                    'file' => $fileUrl
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('An error occurred while creating a dispute', [
                'exception' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the dispute. Please try again later.'
            ], 400);
        }
    }


    private function sendDisputeCreatedEmail($email, $trackingId)
    {
        $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Dispute Created</title>
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
                <h1>Dispute Created Successfully</h1>
            </div>
            <div class='content'>
                <p>Hello,</p>
                <p>Your dispute has been created successfully. Please find your tracking ID below:</p>
                <p><strong>Tracking ID: $trackingId</strong></p>
                <p>You can use this ID to track the status of your dispute.</p>
                <p>If you did not request this dispute, please contact support.</p>
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
            'Subject' => 'Dispute Created Successfully',
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
        }
    }

    public function deleteDispute(Request $request)
    {
        $response = ['status' => false, 'message' => ''];
        Log::info("delete request");
        Log::info($request);


        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json(["message" => "Authorization header not found", 'status' => false], 401);
        }

        if (strpos($authHeader, 'Bearer ') !== 0) {
            return response()->json(["message" => "Malformed authorization header", 'status' => false], 400);
        }

        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
        } catch (\Exception $e) {
            return response()->json(["message" => "Invalid token", 'status' => false], 401);
        }

        if (!isset($decoded->data->id)) {
            return response()->json(['status' => false, 'message' => 'Invalid data structure.']);
        }

        Log::info(serialize($decoded));

        $userId = $decoded->data->id;
        $disputeId = $request->id;
        $role  = $decoded->data->role;

        if (!$disputeId) {
            $response['message'] = 'Dispute ID is required';
            Log::info('Dispute ID not provided for deletion');
            return response()->json($response);
        }

        try {
            if ($role === "super_admin") {

                DB::table('disputes')->where('id', $disputeId)->delete();

                $response['status'] = true;
                $response['message'] = 'Dispute deleted successfully';
                Log::info('Dispute deleted successfully with ID: ' . $disputeId);
            } else {
                $dispute = DB::table('disputes')->where('id', $disputeId)->where('user_id', $userId)->first();

                if (!$dispute) {
                    $response['message'] = 'Dispute not found or unauthorized';
                    Log::info('Dispute not found or unauthorized for ID: ' . $disputeId);
                    return response()->json($response);
                }
                DB::table('disputes')->where('id', $disputeId)->delete();

                $response['status'] = true;
                $response['message'] = 'Dispute deleted successfully';
                Log::info('Dispute deleted successfully with ID: ' . $disputeId);
            }
        } catch (\Exception $e) {
            $response['message'] = 'Database error: ' . $e->getMessage();
            Log::error('Database error during dispute deletion: ' . $e->getMessage());
        }

        return response()->json($response);
    }
    public function fetchDisputes(Request $request)
    {
        $response = ['status' => false, 'message' => '', 'data' => [], 'analytics' => []];

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

        Log::info('Incoming request to fetch disputes', (array)$decoded);

        $userData = $decoded->data ?? null;

        if ($userData && isset($userData->id)) {
            $userId = $userData->id;
            $userRole = $userData->role;
            Log::info('User data', (array)$userData);

            try {
                // Base query for disputes
                $query = DB::table('disputes')
                    ->leftJoin('dispute_files', 'disputes.id', '=', 'dispute_files.dispute_id')
                    ->select('disputes.*', 'dispute_files.file_path');

                if ($userRole === 'super_admin') {
                    Log::info('Super admin detected, fetching all disputes');

                    // Fetch all disputes and associated file paths if the user is a super admin
                    $disputes = $query->get();
                } else {
                    Log::info('Regular user detected, fetching disputes for user ID: ' . $userId);

                    // Fetch only the user's disputes and associated file paths otherwise
                    $disputes = $query->where('disputes.user_id', $userId)->get();
                }

                if ($disputes->isNotEmpty()) {
                    $response['status'] = true;
                    $response['message'] = 'Disputes fetched successfully';
                    $response['data'] = $disputes;

                    // Analytics
                    $response['analytics'] = $this->getDisputeAnalytics($userRole, $userId);

                    Log::info('Disputes fetched successfully for user ID: ' . $userId);
                } else {
                    $response['message'] = 'No disputes found';
                    Log::info('No disputes found for user ID: ' . $userId);
                }
            } catch (\Exception $e) {
                $response['message'] = 'Database error: ' . $e->getMessage();
                Log::error('Database error during fetching disputes: ' . $e->getMessage());
            }

            return response()->json($response);
        }

        $response['message'] = 'Error, try again later';
        Log::error('Unauthorized access attempt for fetching disputes');
        return response()->json($response);
    }
    private function getDisputeAnalytics($userRole, $userId)
    {
        $analytics = [];

        try {
            // Dispute Counts by Status
            $statusCounts = DB::table('disputes')
                ->select('status', DB::raw('count(id) as count'))
                ->groupBy('status')
                ->get();

            $analytics['statusCounts'] = $statusCounts;

            // Dispute Counts by Category
            $categoryCounts = DB::table('disputes')
                ->leftJoin('dispute_categories', 'disputes.category_id', '=', 'dispute_categories.id')
                ->select('dispute_categories.name as category', DB::raw('count(disputes.id) as count'))
                ->groupBy('dispute_categories.name')
                ->get();

            $analytics['categoryCounts'] = $categoryCounts;

            // Dispute Trends Over Time
            $trends = DB::table('disputes')
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(id) as count'))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get();

            $analytics['trends'] = $trends;
        } catch (\Exception $e) {
            Log::error('Error fetching dispute analytics: ' . $e->getMessage());
            $analytics['error'] = 'Error fetching analytics data';
        }

        return $analytics;
    }

    public function getDisputeById(Request $request, $id)
    {
        $response = ['status' => false, 'message' => '', 'data' => []];

        if ($request->getMethod() !== 'GET') {
            $response['message'] = 'Invalid request method';
            Log::error('Invalid request method for fetching dispute by ID');
            return response()->json($response);
        }

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

        Log::info('Incoming request to fetch dispute by ID', (array)$decoded);

        $userData = $decoded->data ?? null;

        if ($userData && isset($userData->id)) {
            $userId = $userData->id;
            $role = $userData->role;

            try {
                DB::beginTransaction();
                if ($role === "super_admin" || $role === "admin") {

                    $dispute = DB::table('disputes')
                        ->where(
                            'id',
                            $id
                        )->first();
                } else {

                    $dispute = DB::table('disputes')
                        ->where(
                            'id',
                            $id
                        )
                        ->where('user_id', $userId)
                        ->first();
                }



                if ($dispute) {
                    $category = DB::table('dispute_categories')
                        ->where('id', $dispute->category_id)
                        ->value('name');

                    $subCategory = DB::table('dispute_subcategories')
                        ->where('id', $dispute->subcategory_id)
                        ->value('name');

                    $filePath = DB::table('dispute_files')
                        ->where('dispute_id', $id)
                        ->value('file_path');

                    $categories = DB::table('dispute_categories')->get();
                    $subCategories = DB::table('dispute_subcategories')->get();

                    DB::commit();

                    return response()->json([
                        'status' => true,
                        'dispute' => $dispute,
                        'category' => $category,
                        'subCategory' => $subCategory,
                        'filePath' => $filePath,
                        'categories' => $categories,
                        'subCategories' => $subCategories,
                    ]);
                } else {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => 'Dispute not found']);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
            }
        } else {
            return response()->json(['status' => false, 'message' => 'User ID not found in the decoded data']);
        }
    }
    public function getDisputeByIdForView(Request $request, $id)
    {
        $response = ['status' => false, 'message' => '', 'data' => []];

        if ($request->getMethod() !== 'GET') {
            $response['message'] = 'Invalid request method';
            Log::error('Invalid request method for fetching dispute by ID');
            return response()->json($response);
        }

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

        Log::info('Incoming request to fetch dispute by ID', (array)$decoded);

        $userData = $decoded->data ?? null;

        if ($userData && isset($userData->id)) {
            $userId = $userData->id;
            $role = $userData->role;

            try {
                DB::beginTransaction();
                if ($role === "super_admin" || $role === "admin") {
                    $dispute = DB::table('disputes')
                        ->where('id', $id)
                        ->first();
                } else {
                    $dispute = DB::table('disputes')
                        ->where('id', $id)
                        ->where('user_id', $userId)
                        ->first();
                }

                if ($dispute) {
                    $category = DB::table('dispute_categories')
                        ->where('id', $dispute->category_id)
                        ->value('name');

                    $subCategory = DB::table('dispute_subcategories')
                        ->where('id', $dispute->subcategory_id)
                        ->value('name');

                    $filePath = DB::table('dispute_files')
                        ->where('dispute_id', $id)
                        ->value('file_path');

                    // Return the dispute with the category and subcategory names
                    $replies = DB::table('replies')->where('dispute_id', $id)->get();

                    DB::commit();

                    return response()->json([
                        'status' => true,
                        'dispute' => [
                            'id' => $dispute->id,
                            'title' => $dispute->title,
                            'description' => $dispute->description,
                            'start_time' => $dispute->start_time,
                            'end_time' => $dispute->end_time,
                            'tracking_id' => $dispute->tracking_id,
                            'status' => $dispute->status,
                            'created_at' => $dispute->created_at,
                            'updated_at' => $dispute->updated_at,
                            'resolved_at' => $dispute->resolved_at,
                            'category' => $category,
                            'subcategory' => $subCategory,
                            'file_path' => $filePath,
                            'replies' => $replies
                        ],
                    ]);
                } else {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => 'Dispute not found']);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
            }
        } else {
            return response()->json(['status' => false, 'message' => 'User ID not found in the decoded data']);
        }
    }


    public function updateDispute(Request $request)
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
            return response()->json(["error" => "Authorization header not found or malformed"], 401);
        }

        // Extract and decode the JWT token
        $token = str_replace('Bearer ', '', $authHeader);
        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->data->id ?? null;

            if (!$userId) {
                return response()->json(['message' => 'Invalid token'], 400);
            }

            if ($request->isMethod('post')) {
                // Extract data from request
                $disputeId = $request->input('id');
                $categoryId = $request->input('category_id');
                $subCategoryId = $request->input('subcategory_id');
                $description = $request->input('description');
                $title = $request->input('title'); // Added title field
                $startTime = $request->input('start_time');
                $endTime = $request->input('end_time');
                $status = $request->input('status', 'pending'); // Default status to 'pending' if not provided

                // Validate input
                if (!$disputeId || !$categoryId || !$subCategoryId || !$description || !$title || !$startTime || !$endTime) {
                    return response()->json(['message' => 'Missing required fields'], 400);
                }

                // Handle file upload if present
                $filePath = null;
                if ($request->hasFile('file') && $request->file('file')->isValid()) {
                    $file = $request->file('file');
                    $filePath = Cloudinary::uploadFile($request->file('file')->getRealPath())->getSecurePath();
                    Log::info($filePath);
                    if (!$filePath) {
                        return response()->json(['message' => 'File upload error'], 500);
                    }
                }

                try {
                    // Check if dispute exists and belongs to the user
                    $dispute = DB::table('disputes')->where('id', $disputeId)->first();

                    if (!$dispute) {
                        return response()->json(['message' => 'Dispute not found'], 404);
                    }

                    if ($dispute->user_id != $userId) {
                        return response()->json(['message' => 'Unauthorized'], 403);
                    }

                    // Update the dispute in the database
                    $updateData = [
                        'category_id' => $categoryId,
                        'subcategory_id' => $subCategoryId,
                        'description' => $description,
                        'title' => $title, // Include the title in the update
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'status' => $status,
                    ];

                    if ($filePath) {
                        DB::table('dispute_files')->where('dispute_id', $disputeId)->update(['file_path' => $filePath]);
                    }

                    DB::table('disputes')->where('id', $disputeId)->update($updateData);

                    $response['status'] = true;
                    $response['message'] = 'Dispute updated successfully';
                    Log::info('Dispute updated successfully for user ID: ' . $userId);
                } catch (\Exception $e) {
                    $response['message'] = 'Database error: ' . $e->getMessage();
                    Log::error('Database error during dispute update: ' . $e->getMessage());
                }
                return response()->json($response);
            } else {
                return response()->json(['message' => 'Invalid request method', 'status' => false], 405);
            }
        } catch (\Exception $e) {
            Log::error('Token validation failed: ' . $e->getMessage());
            return response()->json(['message' =>  $e->getMessage(), 'status' => false], 400);
        }
    }

    public function addReply(Request $request)
    {
        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'dispute_id' => 'required|exists:disputes,id',
            'reply' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'status' => false
            ], 422);
        }

        $validatedData = $validator->validated();

        // Decode JWT to get user info
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }

        $token = str_replace('Bearer ', '', $authHeader);
        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->data->id;
            $userEmail = $decoded->data->email;
            $userGroup = $decoded->data->group;
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
        }
        DB::beginTransaction();

        try {
            // Insert reply into database
            $dispute = DB::table('disputes')->where('id', $validatedData['dispute_id'])->first();

            if ($dispute) {
                if ($dispute->status === 'resolved') {
                    DB::table('disputes')
                        ->where('id', $validatedData['dispute_id'])
                        ->update(['status' => 'in_progress']);
                }
            }
            DB::table('replies')->insert([
                'dispute_id' => $validatedData['dispute_id'],
                'user_id' => $userId,
                'email' => $userEmail,
                'group' => $userGroup,
                'reply' => $validatedData['reply'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $trackingId = DB::table('disputes')
                ->where('id', $validatedData['dispute_id'])
                ->value('tracking_id');
            $this->sendReplyEmail($trackingId, $validatedData['reply'], $userEmail);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Reply added successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding reply: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Error adding reply: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function sendReplyEmail($disputeTrackingId, $replyContent, $userEmail)
    {


        $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>New Comment on Dispute</title>
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
                <h1>New Comment on Your Dispute</h1>
            </div>
            <div class='content'>
                <p>Hello,</p>
                <p>A new comment has been added to the dispute with the following details:</p>
                <p><strong>Dispute Tracking ID: $disputeTrackingId</strong></p>
                <p><strong>Comment:</strong> $replyContent</p>
                <p><strong>Replied By:</strong> $userEmail</p>
                <p>If you have any questions or need further assistance, please contact support.</p>
            </div>
            <div class='footer'>
                <p>&copy; Sterling Dispute Portal. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
        $emails = DB::table('users')->pluck('email')->toArray();

        // Convert array of emails to comma-separated string
        $emailString = implode(',', $emails);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.postmarkapp.com/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'From' => 'support@ringo.ng',
            'To' => $emailString,
            'Subject' => 'Dispute Information Updated',
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
        }
    }

    public function resolveDisputesCronJob()
    {
        try {
            // Get all disputes that have replies
            Log::info('Cron job executed');
            $disputes = DB::table('replies')
                ->select('dispute_id', DB::raw('MAX(updated_at) as last_updated'), 'group')
                ->groupBy('dispute_id', 'group')
                ->having('group', '=', 'ringo')
                ->get();

            foreach ($disputes as $dispute) {
                // Calculate time difference between last reply and now
                $lastReplyTime = new Carbon($dispute->last_updated);
                $currentTime = Carbon::now();

                // Check if 24 hours have passed since the last Ringo reply
                if ($currentTime->diffInHours($lastReplyTime) >= 24) {
                    // Check if there are no Sterling replies after the last Ringo reply
                    $sterlingResponseCount = DB::table('replies')
                        ->where('dispute_id', $dispute->dispute_id)
                        ->where('group', 'sterling')
                        ->where('updated_at', '>', $dispute->last_updated)
                        ->count();

                    if ($sterlingResponseCount === 0) {
                        // Update the dispute status to resolved
                        DB::table('disputes')
                            ->where('id', $dispute->dispute_id)
                            ->update(['status' => 'resolved']);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in resolving disputes: ' . $e->getMessage());
        }
    }
}
