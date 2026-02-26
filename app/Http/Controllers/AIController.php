<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Task;

class AIController extends Controller
{
    private $openaiKey;

    public function __construct()
    {
        $this->openaiKey = env('OPENAI_API_KEY');
    }

    /**
     * Chat with AI assistant (with function calling)
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'conversation_history' => 'array|nullable'
        ]);

        $userMessage = $validated['message'];
        $history = $validated['conversation_history'] ?? [];

        try {
            // Get user's current tasks for context (FRESH data every time)
            $currentTasks = $request->user()->tasks()->get();
            
            // Call AI with function calling capability
            $result = $this->callOpenAIWithTools($userMessage, $history, $currentTasks, $request->user());

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $result['message'],
                    'actions_taken' => $result['actions'] ?? [],
                    'timestamp' => now()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'AI request failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Call OpenAI with function calling (Standard API)
     */
    private function callOpenAIWithTools($userMessage, $history, $currentTasks, $user)
    {
        // Calculate fresh counts
        $totalCount = $currentTasks->count();
        $completedCount = $currentTasks->where('is_completed', true)->count();
        $pendingCount = $currentTasks->where('is_completed', false)->count();

        // Debug logging
        \Log::info('AI Chat - Fresh Task Data', [
            'user_id' => $user->id,
            'total_tasks' => $totalCount,
            'completed_tasks' => $completedCount,
            'pending_tasks' => $pendingCount,
            'user_message' => $userMessage
        ]);

        // Build system context with current tasks
        $tasksList = $currentTasks->map(function($task) {
            $status = $task->is_completed ? 'completed' : 'pending';
            return "[ID: {$task->id}] {$task->title} ({$status})";
        })->join("\n");

        $systemPrompt = "You are a helpful task management assistant for {$user->name}.

CURRENT TASKS ({$totalCount} total, {$completedCount} completed, {$pendingCount} pending):
{$tasksList}

CRITICAL INSTRUCTIONS:
1. When counting tasks, use EXACTLY these numbers: {$totalCount} total, {$completedCount} completed, {$pendingCount} pending
2. When deleting by name, search CASE-INSENSITIVELY and return the task ID
3. You CAN delete multiple tasks at once - use the 'delete_multiple' action
4. You CAN create multiple tasks at once - use the 'create_multiple_tasks' action
5. When creating tasks, ALWAYS ask for a due date if not provided
6. Always be accurate - the task list above is the CURRENT, LIVE source of truth
7. Respond in JSON format only

You can help users manage their tasks by:
1. Listing their current tasks
2. Creating single or multiple tasks (with smart defaults)
3. Marking tasks as complete/incomplete
4. Deleting single tasks (by ID or name)
5. Deleting multiple tasks at once (by status or IDs)

TASK CREATION RULES:
- If user provides ONLY a task title (no due date), use action \"ask_for_details\" to ask when it's due
- If user provides task title AND due date, use action \"create_task_smart\" to create with AI analysis
- If user wants to create MULTIPLE tasks, use action \"create_multiple_tasks\" with an array of tasks
- The system will automatically analyze and set priority + estimated hours

RESPONSE FORMAT (respond with ONLY valid JSON):
{
  \"action\": \"create_task_smart\" | \"create_multiple_tasks\" | \"ask_for_details\" | \"complete_task\" | \"delete_task\" | \"delete_multiple\" | \"list_tasks\" | \"none\",
  \"task_id\": 123,
  \"task_ids\": [1, 2, 3],
  \"task_title\": \"task name\",
  \"due_date\": \"2026-02-27\",
  \"tasks\": [
    {\"title\": \"Task 1\", \"due_date\": \"2026-02-27\"},
    {\"title\": \"Task 2\", \"due_date\": \"2026-02-28\"}
  ],
  \"delete_criteria\": \"completed\" | \"all\" | \"pending\",
  \"response\": \"Your friendly message to the user\"
}

EXAMPLES:

User: \"add a task to go jogging\"
{\"action\": \"ask_for_details\", \"task_title\": \"Jogging\", \"response\": \"Sure! When would you like to complete 'Jogging'? (e.g., tomorrow, next week, Feb 28)\"}

User: \"add jogging for tomorrow\"
{\"action\": \"create_task_smart\", \"task_title\": \"Jogging\", \"due_date\": \"2026-02-27\", \"response\": \"I'll add 'Jogging' with a smart analysis!\"}

User: \"create tasks for Market Research, Define Purpose, and Create Wireframe all due on May 26\"
{\"action\": \"create_multiple_tasks\", \"tasks\": [{\"title\": \"Market Research\", \"due_date\": \"2026-05-26\"}, {\"title\": \"Define Purpose\", \"due_date\": \"2026-05-26\"}, {\"title\": \"Create Wireframe\", \"due_date\": \"2026-05-26\"}], \"response\": \"I'll create all these tasks!\"}

User: \"how many tasks do I have?\"
{\"action\": \"none\", \"response\": \"You have {$totalCount} tasks total ({$pendingCount} pending, {$completedCount} completed).\"}

User: \"delete all completed tasks\"
{\"action\": \"delete_multiple\", \"delete_criteria\": \"completed\", \"response\": \"I'll delete all completed tasks for you.\"}

User: \"list all my tasks\"
{\"action\": \"list_tasks\", \"response\": \"Here are your tasks:\"}

User: \"delete praying\"
{\"action\": \"delete_task\", \"task_title\": \"Praying\", \"response\": \"I've deleted 'Praying' from your tasks!\"}

REMEMBER: When creating multiple tasks, use create_multiple_tasks action with a tasks array. Always ask for due dates when creating tasks, then use AI to analyze priority and estimated hours automatically.";

        // Build messages array
        $messages = [];
        
        // Add system message
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt
        ];

        // Add conversation history
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = [
                    'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $msg['content']
                ];
            }
        }

        // Add new user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage
        ];

        // Call OpenAI Chat Completions API
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->openaiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'temperature' => 0.3,
                'max_tokens' => 2048,
            ]);

        if ($response->failed()) {
            \Log::error('OpenAI API Failed', ['body' => $response->body()]);
            throw new \Exception('OpenAI API failed: ' . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['choices'][0]['message']['content'])) {
            \Log::error('Invalid OpenAI Response', ['data' => $data]);
            throw new \Exception('Invalid OpenAI response format');
        }

        $aiResponse = $data['choices'][0]['message']['content'];

        // Log AI response
        \Log::info('AI Response', ['response' => $aiResponse]);

        // Try to parse as JSON
        $aiResponse = trim($aiResponse);
        
        // Remove markdown code blocks if present
        $aiResponse = preg_replace('/```json\s*/i', '', $aiResponse);
        $aiResponse = preg_replace('/```\s*$/i', '', $aiResponse);
        $aiResponse = trim($aiResponse);

        // Extract JSON from response
        $firstBrace = strpos($aiResponse, '{');
        $lastBrace = strrpos($aiResponse, '}');
        
        if ($firstBrace !== false && $lastBrace !== false) {
            $jsonStr = substr($aiResponse, $firstBrace, $lastBrace - $firstBrace + 1);
            $parsed = json_decode($jsonStr, true);
        } else {
            $parsed = json_decode($aiResponse, true);
        }

        // If not JSON, treat as plain text
        if (!$parsed) {
            return [
                'message' => $aiResponse,
                'actions' => []
            ];
        }

        // Execute the action
        $actionsTaken = [];
        
        switch ($parsed['action'] ?? 'none') {
            case 'ask_for_details':
                // Just return the AI's question asking for more details
                // No action taken, waiting for user response
                break;

            case 'create_task_smart':
                if (isset($parsed['task_title'])) {
                    $title = $parsed['task_title'];
                    $dueDate = $parsed['due_date'] ?? null;
                    
                    // Call AI to analyze the task
                    $analysis = $this->analyzeTaskInternal($title, $dueDate, $user);
                    
                    // Create task with smart defaults
                    $task = $user->tasks()->create([
                        'title' => $title,
                        'due_date' => $dueDate,
                        'priority' => $analysis['priority'],
                        'estimated_hours' => $analysis['estimated_hours'],
                        'is_completed' => false
                    ]);
                    
                    $actionsTaken[] = "Created task: {$task->title}";
                    $parsed['response'] = "✅ I've added '{$title}' to your tasks!\n\n🤖 AI Analysis:\n- Priority: " . strtoupper($analysis['priority']) . "\n- Estimated: {$analysis['estimated_hours']} hours\n- Reason: {$analysis['reasoning']}";
                }
                break;

            case 'create_multiple_tasks':
                if (isset($parsed['tasks']) && is_array($parsed['tasks'])) {
                    $createdTasks = [];
                    
                    foreach ($parsed['tasks'] as $taskData) {
                        if (!isset($taskData['title'])) continue;
                        
                        $title = $taskData['title'];
                        $dueDate = $taskData['due_date'] ?? null;
                        
                        // Call AI to analyze each task
                        $analysis = $this->analyzeTaskInternal($title, $dueDate, $user);
                        
                        // Create task with smart defaults
                        $task = $user->tasks()->create([
                            'title' => $title,
                            'due_date' => $dueDate,
                            'priority' => $analysis['priority'],
                            'estimated_hours' => $analysis['estimated_hours'],
                            'is_completed' => false
                        ]);
                        
                        $createdTasks[] = [
                            'title' => $task->title,
                            'priority' => strtoupper($analysis['priority']),
                            'estimated_hours' => $analysis['estimated_hours']
                        ];
                    }
                    
                    $count = count($createdTasks);
                    $taskSummary = array_map(function($t) {
                        $emoji = ['HIGH' => '🔴', 'MEDIUM' => '🟡', 'LOW' => '🟢'][$t['priority']] ?? '';
                        return "✅ {$t['title']} ({$emoji} {$t['priority']}, {$t['estimated_hours']}h)";
                    }, $createdTasks);
                    
                    $actionsTaken[] = "Created {$count} tasks";
                    $parsed['response'] = "🎉 I've created {$count} tasks with AI analysis!\n\n" . implode("\n", $taskSummary);
                }
                break;
            
            case 'list_tasks':
                // Format tasks as a nice string response
                $taskList = $currentTasks->map(function($task) {
                    $status = $task->is_completed ? '✅' : '⬜';
                    $priority = '';
                    if ($task->priority) {
                        $priorityLabel = strtoupper($task->priority);
                        $emoji = [
                            'HIGH' => '🔴',
                            'MEDIUM' => '🟡',
                            'LOW' => '🟢'
                        ][$priorityLabel] ?? '';
                        $priority = "{$emoji} {$priorityLabel} - ";
                    }
                    return "{$status} {$priority}{$task->title}";
                })->join("\n");
                
                if ($currentTasks->isEmpty()) {
                    $parsed['response'] = "You don't have any tasks yet! 🎉 Would you like me to create one?";
                } else {
                    $parsed['response'] = "Here are your tasks:\n\n{$taskList}\n\nTotal: {$totalCount} tasks ({$pendingCount} pending, {$completedCount} completed)";
                }
                $actionsTaken[] = "Listed all tasks";
                break;

            case 'complete_task':
                if (isset($parsed['task_id'])) {
                    $task = $user->tasks()->find($parsed['task_id']);
                    if ($task) {
                        $task->update(['is_completed' => true]);
                        $actionsTaken[] = "Completed task: {$task->title}";
                    } else {
                        $parsed['response'] = "I couldn't find task ID {$parsed['task_id']}.";
                    }
                }
                break;

            case 'delete_task':
                $task = null;
                
                // Try to find by ID first
                if (isset($parsed['task_id'])) {
                    $task = $user->tasks()->find($parsed['task_id']);
                }
                
                // If no ID provided or not found, try to find by title (case-insensitive)
                if (!$task && isset($parsed['task_title'])) {
                    $task = $user->tasks()
                        ->whereRaw('LOWER(title) = ?', [strtolower($parsed['task_title'])])
                        ->first();
                }
                
                if ($task) {
                    $title = $task->title;
                    $task->delete();
                    $actionsTaken[] = "Deleted task: {$title}";
                    $parsed['response'] = "I've deleted '{$title}' from your tasks!";
                } else {
                    $searchTerm = $parsed['task_title'] ?? $parsed['task_id'] ?? 'that task';
                    $parsed['response'] = "I couldn't find a task matching '{$searchTerm}'. Could you be more specific?";
                }
                break;

            case 'delete_multiple':
                $deletedTasks = [];
                $criteria = $parsed['delete_criteria'] ?? null;
                
                if ($criteria === 'completed') {
                    $tasksToDelete = $user->tasks()->where('is_completed', true)->get();
                } elseif ($criteria === 'all') {
                    $tasksToDelete = $user->tasks()->get();
                } elseif ($criteria === 'pending') {
                    $tasksToDelete = $user->tasks()->where('is_completed', false)->get();
                } elseif (isset($parsed['task_ids']) && is_array($parsed['task_ids'])) {
                    $tasksToDelete = $user->tasks()->whereIn('id', $parsed['task_ids'])->get();
                } else {
                    $tasksToDelete = collect();
                }
                
                if ($tasksToDelete->isNotEmpty()) {
                    foreach ($tasksToDelete as $task) {
                        $deletedTasks[] = $task->title;
                    }
                    $tasksToDelete->each->delete();
                    
                    $count = count($deletedTasks);
                    $actionsTaken[] = "Deleted {$count} tasks: " . implode(', ', $deletedTasks);
                    $parsed['response'] = "I've deleted {$count} task" . ($count > 1 ? 's' : '') . ": " . implode(', ', $deletedTasks);
                } else {
                    $parsed['response'] = "I couldn't find any tasks matching that criteria.";
                }
                break;
        }

        // Log final action
        \Log::info('AI Action Executed', [
            'action' => $parsed['action'] ?? 'none',
            'actions_taken' => $actionsTaken
        ]);

        return [
            'message' => $parsed['response'] ?? $aiResponse,
            'actions' => $actionsTaken
        ];
    }

    /**
     * Internal method to analyze task (used by chat) - OpenAI version
     */
    private function analyzeTaskInternal($title, $dueDate, $user)
    {
        $currentTasks = $user->tasks()->where('is_completed', false)->get();
        $taskCount = $currentTasks->count();

        $daysUntilDue = 'Not set';
        if ($dueDate) {
            $daysUntilDue = now()->diffInDays($dueDate, false);
            $daysUntilDue = $daysUntilDue . ' days';
        }

        $prompt = "Analyze this task and determine priority and estimated hours.

TASK: {$title}
DUE: {$daysUntilDue}
WORKLOAD: {$taskCount} pending tasks

PRIORITY RULES:
- HIGH: Due in 0-2 days OR urgent keywords
- MEDIUM: Due in 3-7 days OR moderate task
- LOW: Due in 8+ days OR simple task

ESTIMATION RULES:
- Simple tasks: 0.5-2 hours
- Medium tasks: 2-8 hours
- Complex tasks: 8-40 hours

Respond ONLY with this JSON (no markdown, no explanation):
{
  \"priority\": \"high\",
  \"estimated_hours\": 2,
  \"reasoning\": \"Brief explanation\"
}";

        try {
            // Call OpenAI for analysis
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->openaiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a task analysis AI. Respond only with valid JSON.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 500,
                ]);

            if ($response->failed()) {
                throw new \Exception('AI analysis failed');
            }

            $data = $response->json();
            $aiResponse = $data['choices'][0]['message']['content'] ?? '';

            // Clean response
            $aiResponse = trim($aiResponse);
            $aiResponse = preg_replace('/```json\s*/i', '', $aiResponse);
            $aiResponse = preg_replace('/```\s*$/i', '', $aiResponse);

            // Extract JSON
            $firstBrace = strpos($aiResponse, '{');
            $lastBrace = strrpos($aiResponse, '}');
            
            if ($firstBrace !== false && $lastBrace !== false) {
                $aiResponse = substr($aiResponse, $firstBrace, $lastBrace - $firstBrace + 1);
            }

            $analysis = json_decode(trim($aiResponse), true);

            if ($analysis) {
                return [
                    'priority' => $analysis['priority'] ?? 'medium',
                    'estimated_hours' => $analysis['estimated_hours'] ?? 2,
                    'reasoning' => $analysis['reasoning'] ?? 'AI analysis complete'
                ];
            }
        } catch (\Exception $e) {
            \Log::error('Task analysis failed', ['error' => $e->getMessage()]);
        }

        // Fallback to rule-based
        $priority = 'medium';
        $estimatedHours = 2;

        if ($dueDate) {
            $days = now()->diffInDays($dueDate, false);
            if ($days <= 2) {
                $priority = 'high';
                $estimatedHours = 1;
            } elseif ($days <= 7) {
                $priority = 'medium';
                $estimatedHours = 2;
            } else {
                $priority = 'low';
                $estimatedHours = 1;
            }
        }

        return [
            'priority' => $priority,
            'estimated_hours' => $estimatedHours,
            'reasoning' => 'Auto-determined based on due date'
        ];
    }

    /**
     * Analyze task and suggest priority & estimated hours (API endpoint)
     * POST /api/ai/analyze-task
     */
    public function analyzeTask(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'due_date' => 'nullable|date',
            'description' => 'nullable|string'
        ]);

        $result = $this->analyzeTaskInternal(
            $validated['title'],
            $validated['due_date'] ?? null,
            $request->user()
        );

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }
}