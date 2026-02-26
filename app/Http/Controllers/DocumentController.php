/**
 * Analyze text with AI and create tasks
 */
private function analyzeWithAI($text, $user)
{
    $systemPrompt = "You are a task extraction AI. Analyze the following document and extract actionable tasks.

RULES:
1. Identify specific action items, deliverables, or things that need to be done
2. Each task should be clear and actionable
3. Extract deadlines if mentioned
4. Categorize by priority if possible (high/medium/low)
5. Return ONLY a JSON object, no markdown, no explanation

Respond with this EXACT JSON structure:
{
  \"summary\": \"Brief summary of the document\",
  \"tasks\": [
    {
      \"title\": \"Clear, actionable task title\",
      \"description\": \"Optional details or context\",
      \"priority\": \"high\" | \"medium\" | \"low\",
      \"deadline\": \"YYYY-MM-DD or null\"
    }
  ]
}

Extract between 3-10 tasks. Focus on the most important actionable items.";

    // Use OpenAI Chat Completions API (CORRECT endpoint)
    $response = Http::timeout(60)
        ->withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type'  => 'application/json',
        ])
        ->post('https://api.openai.com/v1/chat/completions', [  // ← CORRECT ENDPOINT
            'model' => 'gpt-4o-mini',
            'messages' => [  // ← CORRECT FORMAT
                [
                    'role' => 'system',
                    'content' => $systemPrompt
                ],
                [
                    'role' => 'user',
                    'content' => "DOCUMENT TEXT:\n\n" . substr($text, 0, 4000)  // Limit to 4000 chars to avoid token limits
                ]
            ],
            'temperature' => 0.3,
            'max_tokens' => 2048,
        ]);

    if ($response->failed()) {
        throw new \Exception('OpenAI API failed: ' . $response->body());
    }

    $data = $response->json();
    
    if (!isset($data['choices'][0]['message']['content'])) {
        throw new \Exception('Invalid OpenAI response format');
    }
    
    $aiResponse = $data['choices'][0]['message']['content'];  // ← CORRECT response path

    // Clean response
    $aiResponse = trim($aiResponse);
    $aiResponse = preg_replace('/```json\s*/', '', $aiResponse);
    $aiResponse = preg_replace('/```\s*$/', '', $aiResponse);
    $aiResponse = trim($aiResponse);

    // Parse JSON
    $parsed = json_decode($aiResponse, true);

    if (!$parsed || !isset($parsed['tasks'])) {
        throw new \Exception('AI returned invalid format');
    }

    // Create tasks in database
    $createdTasks = [];
    
    foreach ($parsed['tasks'] as $taskData) {
        $task = $user->tasks()->create([
            'title' => $taskData['title'],
            'description' => $taskData['description'] ?? null,
            'is_completed' => false
        ]);
        
        $createdTasks[] = [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $taskData['priority'] ?? 'medium',
            'deadline' => $taskData['deadline'] ?? null
        ];
    }

    return [
        'summary' => $parsed['summary'] ?? 'Document analyzed',
        'tasks' => $createdTasks
    ];
}