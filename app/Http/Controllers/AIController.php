<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Task;
use Carbon\Carbon;

class AIController extends Controller
{
    // Ollama Configuration
    private $ollamaUrl = 'http://localhost:11434/api/generate';
    private $ollamaModel = 'llama3.2';

    /**
     * Add CORS headers to response
     */
    private function corsResponse($data, $status = 200)
    {
        return response()->json($data, $status)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
            ->header('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Call Ollama LLaMA API with timeout protection
     */
    private function callOllama($prompt, $temperature = 0.2, $maxTokens = 500)
    {
        try {
            $response = Http::timeout(30)
                ->post($this->ollamaUrl, [
                    'model' => $this->ollamaModel,
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => $temperature,
                        'num_predict' => $maxTokens,
                    ]
                ]);

            if ($response->failed()) {
                Log::error('Ollama request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            $data = $response->json();
            return $data['response'] ?? null;

        } catch (\Exception $e) {
            Log::error('Ollama Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Analyze task using LLaMA (with fallback)
     */
    private function analyzeTaskInternal($title, $dueDate = null, $taskCount = 0)
    {
        $now = now();
        $daysUntilDue = null;

        // Parse due date
        if ($dueDate) {
            try {
                $dueDateCarbon = Carbon::parse($dueDate);
                $daysUntilDue = $now->diffInDays($dueDateCarbon, false);
            } catch (\Exception $e) {
                Log::warning("Invalid due date: {$dueDate}");
            }
        }

        // Try AI analysis with timeout protection
        try {
            $prompt = $this->buildAnalysisPrompt($title, $daysUntilDue, $now, $taskCount);
            $aiResponse = $this->callOllama($prompt, 0.2, 300);

            if (!$aiResponse) {
                throw new \Exception('LLaMA returned null');
            }

            $parsed = $this->parseAIResponse($aiResponse);

            // Validate priority against business rules
            if ($daysUntilDue !== null) {
                $parsed = $this->validatePriority($parsed, $daysUntilDue);
            }

            return $parsed;

        } catch (\Exception $e) {
            Log::warning('LLaMA analysis failed, using fallback', ['error' => $e->getMessage()]);
            return $this->fallbackAnalysis($daysUntilDue);
        }
    }

    /**
     * Build analysis prompt for LLaMA
     */
    private function buildAnalysisPrompt($title, $daysUntilDue, $now, $taskCount)
    {
        $currentDate = $now->format('Y-m-d (l)');
        
        if ($daysUntilDue !== null) {
            if ($daysUntilDue < 0) {
                $dueDateInfo = "OVERDUE by " . abs($daysUntilDue) . " days";
            } elseif ($daysUntilDue == 0) {
                $dueDateInfo = "Due TODAY";
            } elseif ($daysUntilDue == 1) {
                $dueDateInfo = "Due TOMORROW";
            } else {
                $dueDateInfo = "Due in {$daysUntilDue} days";
            }
        } else {
            $dueDateInfo = "No due date specified";
        }

        return <<<PROMPT
Analyze this task. Respond ONLY with JSON, no markdown.

TASK: "{$title}"
DUE: {$dueDateInfo}
DATE: {$currentDate}

JSON:
{
  "priority": "high/medium/low",
  "estimated_hours": 2,
  "reasoning": "Brief reason"
}
PROMPT;
    }

    /**
     * Parse LLaMA response
     */
    private function parseAIResponse($response)
    {
        $cleaned = preg_replace('/```json\s*/i', '', $response);
        $cleaned = preg_replace('/```\s*/i', '', $cleaned);
        $cleaned = trim($cleaned);

        if (preg_match('/\{[^}]*"priority"[^}]*\}/s', $cleaned, $matches)) {
            $cleaned = $matches[0];
        }

        try {
            $parsed = json_decode($cleaned, true);

            if (!$parsed || !isset($parsed['priority'])) {
                throw new \Exception('Invalid JSON structure');
            }

            return [
                'priority' => strtolower($parsed['priority'] ?? 'medium'),
                'estimated_hours' => floatval($parsed['estimated_hours'] ?? 2),
                'reasoning' => $parsed['reasoning'] ?? 'AI analysis completed'
            ];

        } catch (\Exception $e) {
            throw new \Exception('JSON parsing failed');
        }
    }

    /**
     * Validate AI priority against business rules
     */
    private function validatePriority($parsed, $daysUntilDue)
    {
        $aiPriority = $parsed['priority'];
        $expectedPriority = null;

        if ($daysUntilDue < 0) {
            $expectedPriority = 'high';
        } elseif ($daysUntilDue <= 2) {
            $expectedPriority = 'high';
        } elseif ($daysUntilDue <= 7) {
            $expectedPriority = 'medium';
        } else {
            $expectedPriority = 'low';
        }

        if ($aiPriority !== $expectedPriority) {
            $parsed['priority'] = $expectedPriority;
            $parsed['reasoning'] .= " (Validated)";
        }

        return $parsed;
    }

    /**
     * Fallback rule-based analysis
     */
    private function fallbackAnalysis($daysUntilDue)
    {
        $priority = 'medium';
        $hours = 2;

        if ($daysUntilDue !== null) {
            if ($daysUntilDue < 0 || $daysUntilDue <= 2) {
                $priority = 'high';
                $hours = 4;
            } elseif ($daysUntilDue <= 7) {
                $priority = 'medium';
                $hours = 3;
            } else {
                $priority = 'low';
                $hours = 2;
            }
        }

        return [
            'priority' => $priority,
            'estimated_hours' => $hours,
            'reasoning' => 'Rule-based analysis'
        ];
    }

    /**
     * API Endpoint: Analyze Task (with CORS and timeout protection)
     */
    public function analyzeTask(Request $request)
    {
        try {
            $request->validate([
                'task_title' => 'required|string|max:255',
                'due_date' => 'nullable|date'
            ]);

            $user = Auth::user();
            $taskCount = $user ? Task::where('user_id', $user->id)->where('is_completed', false)->count() : 0;

            $analysis = $this->analyzeTaskInternal(
                $request->task_title,
                $request->due_date,
                $taskCount
            );

            return $this->corsResponse([
                'success' => true,
                'data' => $analysis
            ]);
            
        } catch (\Exception $e) {
            Log::error('analyzeTask error: ' . $e->getMessage());
            
            return $this->corsResponse([
                'success' => true,
                'data' => [
                    'priority' => 'medium',
                    'estimated_hours' => 2,
                    'reasoning' => 'Default analysis'
                ]
            ]);
        }
    }

    /**
     * CHAT: NO LLAMA VERSION (Fast & Reliable)
     */
    public function chat(Request $request)
    {
        try {
            $request->validate([
                'message' => 'required|string',
                'conversation_history' => 'nullable|array'
            ]);

            $userMessage = $request->message;
            $user = Auth::user();

            // Detect and execute actions
            $actions = $this->detectAndExecuteActions($userMessage, $user);

            // If actions executed, return summary
            if (!empty($actions)) {
                $actionSummary = $this->buildActionSummary($actions);
                
                return $this->corsResponse([
                    'success' => true,
                    'data' => [
                        'message' => $actionSummary,
                        'response' => $actionSummary,
                        'actions_taken' => $actions
                    ]
                ]);
            }

            // Get simple response (no AI)
            $response = $this->getSimpleResponse($userMessage);
            
            if (!$response) {
                $response = "I can help you manage your tasks! Try:\n\n• 'create task [title] tomorrow'\n• 'list my tasks'\n• 'complete task [title]'\n• 'delete all tasks'\n• 'how many tasks do I have?'\n\nWhat would you like to do?";
            }

            return $this->corsResponse([
                'success' => true,
                'data' => [
                    'message' => $response,
                    'response' => $response,
                    'actions_taken' => []
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Chat error: ' . $e->getMessage());
            
            return $this->corsResponse([
                'success' => true,
                'data' => [
                    'message' => "I'm ready to help manage your tasks!",
                    'response' => "I'm ready to help manage your tasks!",
                    'actions_taken' => []
                ]
            ]);
        }
    }

    /**
     * Simple rule-based responses (no AI needed)
     */
    private function getSimpleResponse($message)
    {
        $lower = strtolower($message);
        
        // Greetings
        if (preg_match('/^(hello|hi|hey|good morning|good afternoon|good evening)/i', $lower)) {
            return "Hello! I'm your AI task assistant. I can help you create, manage, and organize your tasks. What would you like to do?";
        }
        
        // Who are you
        if (preg_match('/who are you|what are you|tell me about yourself/i', $lower)) {
            return "I'm an AI-powered task management assistant built by Fulkrum Interactive. I help you manage tasks through natural conversation using advanced AI technology. You can ask me to create tasks, list tasks, complete them, or delete them. How can I help you today?";
        }
        
        // Help
        if (preg_match('/help|what can you do|commands/i', $lower)) {
            return "I can help you with:\n\n• Create tasks: 'create task to read book tomorrow'\n• List tasks: 'show my tasks'\n• Complete tasks: 'complete task read book'\n• Delete tasks: 'delete all tasks'\n• Get stats: 'how many tasks do I have?'\n\nJust tell me what you need in natural language!";
        }
        
        // Thank you
        if (preg_match('/thank you|thanks|appreciate/i', $lower)) {
            return "You're welcome! Let me know if you need anything else.";
        }
        
        // Goodbye
        if (preg_match('/bye|goodbye|see you/i', $lower)) {
            return "Goodbye! Feel free to come back anytime you need help with your tasks. Have a great day! 👋";
        }
        
        // Return null if no simple response matches
        return null;
    }

    /**
     * SUPER FLEXIBLE: Detect user intent and execute actions with WORD BOUNDARIES
     */
    private function detectAndExecuteActions($message, $user)
    {
        if (!$user) return [];

        $actions = [];
        $lowerMessage = strtolower($message);

        // ========================================
        // 1. DELETE TASKS (Flexible)
        // ========================================
        
        // Delete all tasks
        if (preg_match('/delete\s+(all|everything|all tasks)/i', $message)) {
            if (preg_match('/completed/i', $message)) {
                $deleted = Task::where('user_id', $user->id)
                              ->where('is_completed', true)
                              ->delete();
                $actions[] = [
                    'action' => 'delete_completed',
                    'count' => $deleted
                ];
                Log::info('Deleted completed tasks', ['count' => $deleted]);
            } else {
                $deleted = Task::where('user_id', $user->id)->delete();
                $actions[] = [
                    'action' => 'delete_all',
                    'count' => $deleted
                ];
                Log::info('Deleted all tasks', ['count' => $deleted]);
            }
        }
        // Delete specific task by title
        elseif (preg_match('/delete/i', $message)) {
            // Extract task title - remove "delete", "task", "the"
            $searchTerm = preg_replace('/(delete|task|the)\s*/i', '', $message);
            $searchTerm = trim($searchTerm);
            
            if ($searchTerm && !in_array(strtolower($searchTerm), ['all', 'everything', 'all tasks'])) {
                $task = Task::where('user_id', $user->id)
                            ->where('title', 'LIKE', "%{$searchTerm}%")
                            ->first();
                
                if ($task) {
                    $taskTitle = $task->title;
                    $task->delete();
                    $actions[] = [
                        'action' => 'delete_task',
                        'task' => $taskTitle
                    ];
                    Log::info('Deleted task', ['title' => $taskTitle]);
                }
            }
        }

        // ========================================
        // 2. COMPLETE TASKS (Flexible)
        // ========================================
        
        // Complete all tasks
        if (preg_match('/complete\s+(all|everything|all tasks)/i', $message)) {
            $updated = Task::where('user_id', $user->id)
                          ->where('is_completed', false)
                          ->update(['is_completed' => true]);
            $actions[] = [
                'action' => 'complete_all',
                'count' => $updated
            ];
            Log::info('Completed all tasks', ['count' => $updated]);
        }
        // Mark as done/finished (alternative phrases)
        elseif (preg_match('/(?:complete|done|finish|finished|mark as done|mark as complete)/i', $message)) {
            // Extract task title - remove action words
            $searchTerm = preg_replace('/(complete|done|finish|finished|mark as done|mark as complete|task|the)\s*/i', '', $message);
            $searchTerm = trim($searchTerm);
            
            if ($searchTerm && !in_array(strtolower($searchTerm), ['all', 'everything', 'all tasks'])) {
                $task = Task::where('user_id', $user->id)
                            ->where('is_completed', false)
                            ->where('title', 'LIKE', "%{$searchTerm}%")
                            ->first();
                
                if ($task) {
                    $task->is_completed = true;
                    $task->save();
                    $actions[] = [
                        'action' => 'complete_task',
                        'task' => $task->title
                    ];
                    Log::info('Completed task', ['title' => $task->title]);
                }
            }
        }

        // ========================================
        // 3. CREATE TASK (Fixed with word boundaries)
        // ========================================
        
        if (preg_match('/\b(create|add|new|make)\b/i', $lowerMessage) && preg_match('/\btask\b/i', $lowerMessage)) {
            $title = null;
            $dueDate = null;
            
            // Step-by-step extraction with word boundaries
            $workingText = $message;
            
            // Remove polite phrases at the start (whole words only)
            $workingText = preg_replace('/^\s*(can you|could you|please|would you|will you)\s+/i', '', $workingText);
            
            // Remove action words (whole words only)
            $workingText = preg_replace('/\b(create|add|new|make)\b\s*/i', '', $workingText);
            
            // Remove article "a" (whole word only)
            $workingText = preg_replace('/\ba\b\s*/i', '', $workingText);
            
            // Remove "task" (whole word only)
            $workingText = preg_replace('/\btask\b\s*/i', '', $workingText);
            
            // Remove "to" at the beginning or after space (whole word only)
            $workingText = preg_replace('/^\s*\bto\b\s+/i', '', $workingText);
            $workingText = preg_replace('/\s+\bto\b\s+/i', ' ', $workingText);
            
            // Remove date-related phrases at the end (whole words)
            $workingText = preg_replace('/\s+(which is|that is)\s+(due\s+)?(tomorrow|today|next week|in \d+ days?)\s*$/i', '', $workingText);
            $workingText = preg_replace('/\s+(due\s+)?(tomorrow|today|next week|in \d+ days?)\s*$/i', '', $workingText);
            
            // Remove standalone "which", "that", "is", "due" at the end
            $workingText = preg_replace('/\s+(which|that|is|due)\s*$/i', '', $workingText);
            
            // Remove trailing commas
            $workingText = preg_replace('/,\s*$/', '', $workingText);
            
            // Clean up extra spaces
            $title = preg_replace('/\s+/', ' ', trim($workingText));
            
            Log::info('Task extraction', [
                'original' => $message,
                'extracted' => $title,
                'length' => strlen($title)
            ]);

            if ($title && strlen($title) > 1) {
                // Detect due date from ORIGINAL message
                if (preg_match('/\btomorrow\b/i', $message)) {
                    $dueDate = now()->addDay()->format('Y-m-d');
                    Log::info('📅 Due: tomorrow', ['date' => $dueDate]);
                } elseif (preg_match('/\btoday\b/i', $message)) {
                    $dueDate = now()->format('Y-m-d');
                    Log::info('📅 Due: today', ['date' => $dueDate]);
                } elseif (preg_match('/next week/i', $message)) {
                    $dueDate = now()->addWeek()->format('Y-m-d');
                    Log::info('📅 Due: next week', ['date' => $dueDate]);
                } elseif (preg_match('/in (\d+) days?/i', $message, $matches)) {
                    $dueDate = now()->addDays((int)$matches[1])->format('Y-m-d');
                    Log::info('📅 Due: in X days', ['days' => $matches[1], 'date' => $dueDate]);
                }

                // SMART PRIORITY DETECTION
                $priority = 'medium';
                $hasHighKeyword = preg_match('/\b(urgent|asap|critical|emergency|important|high priority)\b/i', $message);
                $hasLowKeyword = preg_match('/\b(low priority|when you can|whenever|not urgent)\b/i', $message);
                $dueDatePriority = null;
                
                if ($dueDate) {
                    $daysUntilDue = now()->diffInDays(Carbon::parse($dueDate), false);
                    
                    if ($daysUntilDue < 0) {
                        $dueDatePriority = 'high';
                    } elseif ($daysUntilDue <= 2) {
                        $dueDatePriority = 'high';
                    } elseif ($daysUntilDue <= 7) {
                        $dueDatePriority = 'medium';
                    } else {
                        $dueDatePriority = 'low';
                    }
                }
                
                if ($hasHighKeyword) {
                    $priority = 'high';
                } elseif ($hasLowKeyword) {
                    $priority = 'low';
                } elseif ($dueDatePriority) {
                    $priority = $dueDatePriority;
                } else {
                    $priority = 'medium';
                }
                
                Log::info('Priority determined', [
                    'final' => $priority,
                    'keyword_high' => $hasHighKeyword,
                    'keyword_low' => $hasLowKeyword,
                    'due_date_priority' => $dueDatePriority
                ]);

                // Create task
                try {
                    $task = Task::create([
                        'user_id' => $user->id,
                        'title' => $title,
                        'due_date' => $dueDate,
                        'priority' => $priority,
                        'is_completed' => false
                    ]);
                    
                    Log::info('Task created', [
                        'id' => $task->id,
                        'title' => $task->title,
                        'due_date' => $task->due_date,
                        'priority' => $task->priority
                    ]);
                    
                    $actions[] = [
                        'action' => 'create_task',
                        'task' => $task->title,
                        'due_date' => $task->due_date,
                        'priority' => $task->priority
                    ];
                } catch (\Exception $e) {
                    Log::error('Task creation failed', ['error' => $e->getMessage()]);
                }
            }
        }

        // ========================================
        // 4. LIST TASKS (Flexible)
        // ========================================
        
        if (preg_match('/(?:list|show|display|view|see|what are|get)\s*(?:my|all)?\s*(?:tasks?|to.?dos?)/i', $message) ||
            preg_match('/what do i have to do/i', $message) ||
            preg_match('/show me my list/i', $message)) {
            
            $tasks = Task::where('user_id', $user->id)
                        ->where('is_completed', false)
                        ->orderBy('priority', 'desc')
                        ->orderBy('created_at', 'desc')
                        ->limit(10)
                        ->get(['title', 'priority', 'due_date']);
            
            $actions[] = [
                'action' => 'list_tasks',
                'tasks' => $tasks->toArray()
            ];
            
            Log::info('Listed tasks', ['count' => $tasks->count()]);
        }

        // ========================================
        // 5. GET STATS (Flexible)
        // ========================================
        
        if (preg_match('/(?:how many|count|number of|total)\s*(?:tasks?|to.?dos?)/i', $message) ||
            preg_match('/(?:task|todo)?\s*(?:stats|statistics|summary)/i', $message) ||
            preg_match('/what.?s my progress/i', $message)) {
            
            $total = Task::where('user_id', $user->id)->count();
            $pending = Task::where('user_id', $user->id)->where('is_completed', false)->count();
            $completed = Task::where('user_id', $user->id)->where('is_completed', true)->count();
            
            $actions[] = [
                'action' => 'get_stats',
                'total' => $total,
                'pending' => $pending,
                'completed' => $completed
            ];
            
            Log::info('Task stats', ['total' => $total, 'pending' => $pending, 'completed' => $completed]);
        }

        // ========================================
        // 6. MARK AS INCOMPLETE (Bonus Feature!)
        // ========================================
        
        if (preg_match('/(?:mark as incomplete|mark as not done|uncomplete|undo|reopen)/i', $message)) {
            $searchTerm = preg_replace('/(mark as incomplete|mark as not done|uncomplete|undo|reopen|task|the)\s*/i', '', $message);
            $searchTerm = trim($searchTerm);
            
            if ($searchTerm) {
                $task = Task::where('user_id', $user->id)
                            ->where('is_completed', true)
                            ->where('title', 'LIKE', "%{$searchTerm}%")
                            ->first();
                
                if ($task) {
                    $task->is_completed = false;
                    $task->save();
                    $actions[] = [
                        'action' => 'reopen_task',
                        'task' => $task->title
                    ];
                    Log::info('Reopened task', ['title' => $task->title]);
                }
            }
        }

        // ========================================
        // 7. SHOW COMPLETED TASKS (Bonus Feature!)
        // ========================================
        
        if (preg_match('/(?:show|list|view)\s*(?:my)?\s*completed\s*(?:tasks?)/i', $message)) {
            $tasks = Task::where('user_id', $user->id)
                        ->where('is_completed', true)
                        ->orderBy('updated_at', 'desc')
                        ->limit(10)
                        ->get(['title', 'priority', 'due_date']);
            
            $actions[] = [
                'action' => 'list_completed',
                'tasks' => $tasks->toArray()
            ];
            
            Log::info('Listed completed tasks', ['count' => $tasks->count()]);
        }

        return $actions;
    }

    /**
     * Build action summary for user
     */
    private function buildActionSummary($actions)
    {
        $summaries = [];

        foreach ($actions as $action) {
            switch ($action['action']) {
                case 'delete_all':
                    $summaries[] = "Deleted {$action['count']} task(s) from your list.";
                    break;
                case 'delete_completed':
                    $summaries[] = "Deleted {$action['count']} completed task(s).";
                    break;
                case 'delete_task':
                    $summaries[] = "Deleted task: \"{$action['task']}\".";
                    break;
                case 'complete_all':
                    $summaries[] = "Marked {$action['count']} task(s) as completed.";
                    break;
                case 'complete_task':
                    $summaries[] = "Completed task: \"{$action['task']}\".";
                    break;
                case 'create_task':
                    $due = isset($action['due_date']) && $action['due_date'] 
                        ? " (due: {$action['due_date']})" 
                        : "";
                    $priority = isset($action['priority']) 
                        ? " [" . strtoupper($action['priority']) . "]" 
                        : "";
                    $summaries[] = "Created new task: \"{$action['task']}\"{$priority}{$due}.";
                    break;
                case 'list_tasks':
                    if (empty($action['tasks'])) {
                        $summaries[] = "You have no pending tasks! 🎉";
                    } else {
                        $taskList = "Your pending tasks:\n";
                        foreach ($action['tasks'] as $task) {
                            $priority = strtoupper($task['priority']);
                            $emoji = $task['priority'] === 'high' ? '🔴' : ($task['priority'] === 'medium' ? '🟡' : '🟢');
                            $due = $task['due_date'] ? " (due: {$task['due_date']})" : "";
                            $taskList .= "  {$emoji} {$task['title']} ({$priority}){$due}\n";
                        }
                        $summaries[] = trim($taskList);
                    }
                    break;
                case 'get_stats':
                    $summaries[] = "Task Stats: {$action['total']} total, {$action['pending']} pending, {$action['completed']} completed.";
                    break;
                case 'reopen_task':
                    $summaries[] = "Reopened task: \"{$action['task']}\".";
                    break;
                case 'list_completed':
                    if (empty($action['tasks'])) {
                        $summaries[] = "You haven't completed any tasks yet.";
                    } else {
                        $taskList = "Your completed tasks:\n";
                        foreach ($action['tasks'] as $task) {
                            $priority = strtoupper($task['priority']);
                            $emoji = $task['priority'] === 'high' ? '🔴' : ($task['priority'] === 'medium' ? '🟡' : '🟢');
                            $due = $task['due_date'] ? " (was due: {$task['due_date']})" : "";
                            $taskList .= "  {$emoji} {$task['title']} ({$priority}){$due}\n";
                        }
                        $summaries[] = trim($taskList);
                    }
                    break;
            }
        }

        return implode("\n", $summaries);
    }

    /**
     * Get current date info
     */
    private function getCurrentDateInfo()
    {
        $now = now();
        return $now->format('l, F j, Y');
    }

    /**
     * Document Analysis (with CORS)
     */
    public function analyzeDocument(Request $request)
    {
        try {
            $request->validate([
                'document_text' => 'required|string'
            ]);

            $documentText = $request->document_text;

            $prompt = "Extract tasks from this text. Return JSON only:\n\n{$documentText}\n\nJSON format:\n{\"tasks\":[{\"title\":\"\",\"priority\":\"\",\"estimated_hours\":2}]}";

            $aiResponse = $this->callOllama($prompt, 0.3, 1000);

            if (!$aiResponse) {
                throw new \Exception('AI unavailable');
            }

            $tasks = $this->parseDocumentTasks($aiResponse);

            return $this->corsResponse([
                'success' => true,
                'data' => [
                    'tasks' => $tasks,
                    'raw_response' => $aiResponse
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Document analysis error: ' . $e->getMessage());
            
            return $this->corsResponse([
                'success' => false,
                'message' => 'Document analysis failed'
            ], 500);
        }
    }

    /**
     * Parse tasks from document analysis
     */
    private function parseDocumentTasks($response)
    {
        try {
            $cleaned = preg_replace('/```json\s*/i', '', $response);
            $cleaned = preg_replace('/```\s*/i', '', $cleaned);
            $cleaned = trim($cleaned);

            if (preg_match('/\{.*"tasks".*\}/s', $cleaned, $matches)) {
                $cleaned = $matches[0];
            }

            $parsed = json_decode($cleaned, true);

            return $parsed['tasks'] ?? [];

        } catch (\Exception $e) {
            Log::error('Document task parsing failed');
            return [];
        }
    }
}