<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class CloudinaryHelper
{
    protected $cloudinaryName = 'ringojs';
    protected $cloudinaryApiKey = '596329948937857';
    protected $cloudinaryApiSecret = 'p0pxxTx_rqGDfE8zDDhfT8fstaw';
    protected $uploadPreset = 'ml_default'; // Use your actual preset name

    public function handleFileUpload($file, $disputeId)
    {
        Log::info("Starting file upload process");



        // Initialize response array
        $responses = ['success' => false, 'secure_url' => '', 'error' => ''];

        // Determine the resource type based on file extension
        $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        $resourceType = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff']) ? 'image' : 'raw';

        // Check if file was uploaded without errors

        if ($file->isValid()) {
            Log::info($file->getRealPath());
            $filePath = $file->getRealPath();

            $fileContents = file_get_contents($filePath);

            // Prepare the file for upload
            $fileData = [
                'file' =>
                base64_encode($fileContents), // Use fopen to handle the file properly
                'upload_preset' => $this->uploadPreset,
                'resource_type' => $resourceType
            ];



            $response = Http::withBasicAuth($this->cloudinaryApiKey, $this->cloudinaryApiSecret)
                ->post('https://api.cloudinary.com/v1_1/' . $this->cloudinaryName . '/upload', $fileData);
            dd($response);

            if ($response->successful()) {
                $resultData = $response->json();
                if (isset($resultData['secure_url'])) {
                    $secureUrl = $resultData['secure_url'];

                    // Save file information to the database
                    try {
                        DB::table('dispute_files')->insert([
                            'dispute_id' => $disputeId,
                            'file_path' => $secureUrl
                        ]);

                        Log::info("File uploaded successfully: $secureUrl for dispute ID $disputeId");

                        $responses['success'] = true;
                        $responses['file_path'] = $secureUrl;
                    } catch (\Exception $e) {
                        $responses['error'] = 'Database error: ' . $e->getMessage();
                        Log::error("Database error during file upload: " . $e->getMessage());
                    }
                } else {
                    $responses['error'] = 'Error: ' . (is_array($resultData) ? json_encode($resultData) : 'Unknown error');
                    Log::error("Cloudinary response error: " . (is_array($resultData) ? json_encode($resultData) : 'Unknown error'));
                }
            } else {
                $responses['error'] = 'HTTP error: ' . $response->status();
                Log::error("HTTP error during file upload: " . $response->status());
            }
        } else {
            $responses['error'] = 'File upload error: ' . $file->getErrorMessage();
            Log::error("File upload error: " . $file->getErrorMessage());
        }

        // return response()->json($responses);
    }
}