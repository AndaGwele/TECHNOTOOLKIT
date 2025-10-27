<?php
// cv_analysis.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $cv_text = $data['cv_text'] ?? '';
    $job_description = $data['job_description'] ?? '';

    if (empty($cv_text) || empty($job_description)) {
        http_response_code(400);
        echo json_encode(['error' => 'CV text and job description are required']);
        exit();
    }

    $api_key = "AIzaSyDhdk2VpT0T2l_f0ZXNAEr0_Wf5WYYc6d0";

    $prompt = "You are an expert resume optimizer and ATS system.
Given the CV text and job description below, provide a concise, structured analysis:

1. Strengths: top skills/keywords the CV matches (label as **STRENGTHS**)
2. Weaknesses: missing important skills/keywords (label as **WEAKNESSES**)
3. Improvements: 2–3 actionable steps to increase ATS compatibility (label as **IMPROVEMENTS**)
4. Summarize whether this CV is a strong, moderate, or weak match overall (label as **MATCH SCORE**)

CV TEXT:
{$cv_text}

JOB DESCRIPTION:
{$job_description}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $api_key,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]]
        ])
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        echo $response;
    } else {
        http_response_code($http_code);
        echo json_encode(['error' => 'AI analysis failed']);
    }
}
?>