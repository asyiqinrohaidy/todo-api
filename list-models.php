<?php

$apiKey = 'AIzaSyCOemKe7f-SVX7o67LlNo9bw35Gs_Tq8mc';

$response = file_get_contents(
    "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}"
);

echo $response; 
