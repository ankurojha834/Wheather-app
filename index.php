<?php
header("Content-Type: application/json");

// Ensure config file exists
if (!file_exists("config.php")) {
    exit(json_encode(["error" => "Config file missing"]));
}
require_once "config.php"; // Ensure API keys are defined

$city = isset($_GET['city']) ? urlencode($_GET['city']) : exit(json_encode(["error" => "City name required"]));

// Function to fetch data via cURL
function fetchAPI($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return ["error" => "API request failed: HTTP $http_code"];
    }
    return json_decode($response, true);
}

// Fetch weather data
$weatherUrl = "https://api.openweathermap.org/data/2.5/weather?q={$city}&appid=" . WEATHER_API_KEY . "&units=metric";
$weatherData = fetchAPI($weatherUrl);

// Fetch AQI data
$aqiUrl = "https://api.waqi.info/feed/{$city}/?token=" . AQI_API_KEY;
$aqiData = fetchAPI($aqiUrl);

// Validate responses
if (isset($weatherData["error"]) || isset($aqiData["error"])) {
    exit(json_encode(["error" => "Failed to retrieve data. Please check API keys or city name."]));
}

$temperature = $weatherData['main']['temp'] ?? "N/A";
$weatherCondition = $weatherData['weather'][0]['description'] ?? "N/A";
$aqiValue = $aqiData['data']['aqi'] ?? "N/A";

// Prepare Gemini AI API Request with an instruction for a short response.
$prompt = "Weather: {$weatherCondition}, Temperature: {$temperature}°C, AQI: {$aqiValue}. Provide a brief health recommendation and travel tip in less than 50 words.";

function callGeminiAI($prompt) {
    $apiKey = GEMINI_API_KEY;
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

    $postData = json_encode([
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ]
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    file_put_contents("debug_log.txt", $response); // Log response for debugging

    curl_close($ch);
    return json_decode($response, true);
}

// Call Gemini AI
$geminiResponse = callGeminiAI($prompt);

// Extract and trim the AI response to 50 words if necessary.
$recommendations = "AI recommendations are currently unavailable.";

// Debugging: Log API response
file_put_contents("gemini_response.json", json_encode($geminiResponse, JSON_PRETTY_PRINT));

if (isset($geminiResponse["candidates"][0]["content"]["parts"][0]["text"])) {
    $rawRecommendation = $geminiResponse["candidates"][0]["content"]["parts"][0]["text"];
    $words = explode(" ", $rawRecommendation);
    if (count($words) > 50) {
        $recommendations = implode(" ", array_slice($words, 0, 50)) . '...';
    } else {
        $recommendations = $rawRecommendation;
    }
} else {
    $recommendations = "Gemini API response format changed or failed.";
}

// Return JSON response
echo json_encode([
    "city" => urldecode($city),
    "temperature" => $temperature,
    "weather" => $weatherCondition,
    "aqi" => $aqiValue,
    "recommendations" => $recommendations
]);
?>
