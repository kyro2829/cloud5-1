<?php
$supabaseUrl = getenv('SUPABASE_URL');
$supabaseKey = getenv('SUPABASE_KEY');

// Example: Fetch from a Supabase table (e.g., "users")
$ch = curl_init("$supabaseUrl/rest/v1/users");

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $supabaseKey",
    "Authorization: Bearer $supabaseKey",
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
echo "<pre>";
print_r($data); // displays the fetched data
echo "</pre>";
?>
