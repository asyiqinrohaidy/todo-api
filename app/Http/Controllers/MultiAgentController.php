<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Task;

class MultiAgentController extends Controller
{
    private $openaiKey;

    public function __construct()
    {
        $this->openaiKey = env('OPENAI_API_KEY');
    }

    /**
     * Process user goal with multi-agent system
     * POST /api/multi-agent/process
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'goal' => 'required|string',
            'context' => 'string|nullable'
        ]);
 
        $goal = $validated['goal'];
        $context = $validated['context'] ?? '';
        $user = $request->user();

        try {
            // Get current tasks for context
            $currentTasks = $user->tasks()->get();

            // Step 1: Planner Agent - Break down the goal
            $plannerResult = $this->callPlannerAgent($goal, $context, $currentTasks);

            // Step 2: Executor Agent - Analyze execution feasibility
            $executorResult = $this->callExecutorAgent($plannerResult, $currentTasks);

            // Step 3: Reviewer Agent - Quality check & improvements
            $reviewerResult = $this->callReviewerAgent($plannerResult, $executorResult);

            // Step 4: Coordinator Agent - Synthesize & create final plan
            $coordinatorResult = $this->callCoordinatorAgent(
                $goal,
                $plannerResult,
                $executorResult,
                $reviewerResult
            );

            // Create tasks based on coordinator's final plan
            $createdTasks = $this->createTasksFromPlan($coordinatorResult, $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'goal' => $goal,
                    'planner_analysis' => $plannerResult,
                    'executor_analysis' => $executorResult,
                    'reviewer_suggestions' => $reviewerResult,
                    'final_plan' => $coordinatorResult,
                    'tasks_created' => $createdTasks,
                    'agent_conversation' => $this->buildConversationLog(
                        $plannerResult,
                        $executorResult,
                        $reviewerResult,
                        $coordinatorResult
                    )
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Multi-agent processing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Planner Agent - Breaks down goals into tasks
     */
    private function callPlannerAgent($goal, $context, $currentTasks)
    {
        $tasksList = $currentTasks->map(fn($t) => "- {$t->title}")->join("\n");
        if (empty($tasksList)) {
            $tasksList = "No current tasks";
        }

        $prompt = "You are the PLANNER AGENT. You MUST respond with ONLY valid JSON, nothing else.

USER'S GOAL: {$goal}

ADDITIONAL CONTEXT: {$context}

CURRENT TASKS:
{$tasksList}

Respond with ONLY this JSON structure (no markdown, no explanations):
{
  \"analysis\": \"Your strategic analysis of the goal\",
  \"tasks\": [
    {
      \"title\": \"Task name\",
      \"description\": \"What needs to be done\",
      \"difficulty\": \"easy\",
      \"dependencies\": [],
      \"priority\": \"high\"
    }
  ],
  \"estimated_timeline\": \"3 months\"
}

CRITICAL: Return ONLY the JSON object. No other text.";

        return $this->callOpenAI($prompt, 'planner');
    }

    /**
     * Executor Agent - Analyzes execution & progress tracking
     */
    private function callExecutorAgent($plannerResult, $currentTasks)
    {
        $planJson = json_encode($plannerResult, JSON_PRETTY_PRINT);
        $completedCount = $currentTasks->where('is_completed', true)->count();
        $totalCount = $currentTasks->count();

        $prompt = "You are the EXECUTOR AGENT. Respond with ONLY valid JSON, nothing else.

PLANNER'S ANALYSIS:
{$planJson}

CURRENT PROGRESS:
- Total tasks: {$totalCount}
- Completed: {$completedCount}

Respond with ONLY this JSON (no markdown, no explanations):
{
  \"feasibility_score\": 8,
  \"execution_strategy\": \"Your recommended approach\",
  \"potential_blockers\": [\"Challenge 1\", \"Challenge 2\"],
  \"quick_wins\": [\"Quick task 1\"],
  \"risk_assessment\": \"Medium\"
}

CRITICAL: Return ONLY the JSON object.";

        return $this->callOpenAI($prompt, 'executor');
    }

    /**
     * Reviewer Agent - Quality checks & improvements
     */
    private function callReviewerAgent($plannerResult, $executorResult)
    {
        $combinedAnalysis = json_encode([
            'planner' => $plannerResult,
            'executor' => $executorResult
        ], JSON_PRETTY_PRINT);

        $prompt = "You are the REVIEWER AGENT. Respond with ONLY valid JSON, nothing else.

ANALYSIS TO REVIEW:
{$combinedAnalysis}

Respond with ONLY this JSON (no markdown, no explanations):
{
  \"quality_score\": 8,
  \"missing_tasks\": [\"Additional task 1\"],
  \"improvements\": [
    {
      \"task\": \"Task title\",
      \"suggestion\": \"How to improve\"
    }
  ],
  \"best_practices\": [\"Practice 1\", \"Practice 2\"]
}

CRITICAL: Return ONLY the JSON object.";

        return $this->callOpenAI($prompt, 'reviewer');
    }

    /**
     * Coordinator Agent - Synthesizes everything & makes final decisions
     */
    private function callCoordinatorAgent($goal, $plannerResult, $executorResult, $reviewerResult)
    {
        $allAnalysis = json_encode([
            'planner' => $plannerResult,
            'executor' => $executorResult,
            'reviewer' => $reviewerResult
        ], JSON_PRETTY_PRINT);

        $prompt = "You are the COORDINATOR AGENT. Respond with ONLY valid JSON, nothing else.

    GOAL: {$goal}

    ALL AGENT INPUTS:
    {$allAnalysis}

    PRIORITY ASSIGNMENT RULES (CRITICAL PATH ANALYSIS):

    Use CRITICAL PATH methodology to assign priorities:

    HIGH PRIORITY (🔴) - Assign when ALL of these are true:
    1. Task is on the CRITICAL PATH (blocks other essential tasks)
    2. Task has tight deadline OR is time-sensitive
    3. Without this task, the goal cannot be achieved
    4. Task cannot be done in parallel with others

    Examples of HIGH priority:
    - Core development work that everything depends on
    - Final deliverables with hard deadlines
    - Preparation tasks that directly block launch/completion
    - Tasks that must be done sequentially (can't parallelize)

    MEDIUM PRIORITY (🟡) - Assign when:
    1. Task is important but can be done in PARALLEL
    2. Task supports the goal but doesn't block critical path
    3. Task has moderate deadline (not immediate)
    4. Task can be delayed slightly without major impact

    Examples of MEDIUM priority:
    - Research and planning (can be done alongside development)
    - Marketing/promotional work (parallel to core work)
    - Testing (continuous, parallel activity)
    - Documentation and support materials

    LOW PRIORITY (🟢) - Assign when:
    1. Task is optional or nice-to-have
    2. Task has distant deadline (many months/years away)
    3. Task is exploratory with no immediate impact
    4. Task can be postponed without consequences

    Examples of LOW priority:
    - Future enhancements or improvements
    - Long-term strategic planning
    - Optional features
    - Tasks for goals with multi-year timelines

    TIMELINE-BASED PRIORITY ADJUSTMENT:
    - Goals with 0-1 month timeline: 50% HIGH, 40% MEDIUM, 10% LOW
    - Goals with 2-3 month timeline: 40% HIGH, 50% MEDIUM, 10% LOW
    - Goals with 4-12 month timeline: 20% HIGH, 60% MEDIUM, 20% LOW
    - Goals with 1+ year timeline: 10% HIGH, 40% MEDIUM, 50% LOW

    CRITICAL PATH IDENTIFICATION:
    For sequential tasks (A → B → C → D):
    - If B depends on A, and C depends on B: A, B, C are all HIGH (critical path)
    - If X can be done anytime in parallel: X is MEDIUM

    ESTIMATED HOURS GUIDELINES:
    - Quick research/setup: 1-4 hours
    - Standard feature development: 4-12 hours
    - Complex implementation: 12-40 hours
    - Major system development: 40-160 hours
    - Learning entirely new skills: 20-80 hours

    Respond with ONLY this JSON (no markdown, no explanations):
    {
    \"executive_summary\": \"Brief overview highlighting critical path\",
    \"final_tasks\": [
        {
        \"title\": \"Task title\",
        \"description\": \"What needs to be done and why it matters\",
        \"priority\": \"high\" | \"medium\" | \"low\",
        \"phase\": \"Phase 1\" | \"Phase 2\" | \"Phase 3\",
        \"estimated_hours\": 10
        }
    ],
    \"key_insights\": [\"Critical path insight\", \"Risk/blocker insight\"],
    \"next_steps\": [\"Immediate action 1\", \"Immediate action 2\"]
    }

    CRITICAL REMINDERS:
    - Analyze task dependencies - blocking tasks are HIGH
    - Consider the goal's timeline when assigning priorities
    - Final deliverable and its direct dependencies are usually HIGH
    - Parallel/supporting tasks are usually MEDIUM
    - Be realistic - not everything is HIGH priority
    - Return ONLY the JSON object.";

        return $this->callOpenAI($prompt, 'coordinator');
    }


    /**
     * Call OpenAI API for a specific agent
     */
    private function callOpenAI($prompt, $agentRole)
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->openaiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an AI agent. Respond only with valid JSON, no markdown, no explanations.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 2048,
            ]);

        if ($response->failed()) {
            throw new \Exception("Agent '{$agentRole}' API call failed: " . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception("Agent '{$agentRole}' returned invalid response structure");
        }
        
        $aiResponse = $data['choices'][0]['message']['content'];

        // Log the raw response
        \Log::info("Agent {$agentRole} raw response", ['response' => $aiResponse]);

        // Clean response
        $aiResponse = trim($aiResponse);
        
        if (empty($aiResponse)) {
            throw new \Exception("Agent '{$agentRole}' returned empty response");
        }
        
        // Remove markdown code blocks
        $aiResponse = preg_replace('/```json\s*/i', '', $aiResponse);
        $aiResponse = preg_replace('/```\s*$/i', '', $aiResponse);
        $aiResponse = trim($aiResponse);
        
        // First attempt: Try to parse directly
        $parsed = json_decode($aiResponse, true);
        
        // If that fails, try to extract JSON from text
        if ($parsed === null) {
            // Try to find JSON braces
            $firstBrace = strpos($aiResponse, '{');
            $lastBrace = strrpos($aiResponse, '}');
            
            if ($firstBrace !== false && $lastBrace !== false) {
                $jsonStr = substr($aiResponse, $firstBrace, $lastBrace - $firstBrace + 1);
                $parsed = json_decode($jsonStr, true);
            }
        }
        
        if (!is_array($parsed)) {
            \Log::error("Agent {$agentRole} final parse failed", [
                'original' => substr($aiResponse, 0, 500),
                'error' => json_last_error_msg()
            ]);
            
            throw new \Exception("Agent '{$agentRole}' returned unparseable JSON");
        }

        \Log::info("Agent {$agentRole} parsed successfully", ['parsed' => $parsed]);

        return $parsed;
    }

    /**
     * Create tasks from coordinator's final plan
     */
    private function createTasksFromPlan($coordinatorResult, $user)
    {
        $createdTasks = [];

        if (!isset($coordinatorResult['final_tasks']) || !is_array($coordinatorResult['final_tasks'])) {
            throw new \Exception("Coordinator did not return valid final_tasks array");
        }

        foreach ($coordinatorResult['final_tasks'] as $taskData) {
            $description = $taskData['description'] ?? '';
            
            if (isset($taskData['phase'])) {
                $description .= "\n\nPhase: " . $taskData['phase'];
            }
            
            if (isset($taskData['estimated_hours'])) {
                $description .= "\nEstimated: " . $taskData['estimated_hours'] . " hours";
            }

            // Map priority to lowercase (high/medium/low)
            $priority = 'medium'; // default
            if (isset($taskData['priority'])) {
                $priority = strtolower($taskData['priority']);
            }

            // Create task with priority and estimated hours
            $task = $user->tasks()->create([
                'title' => $taskData['title'],
                'description' => $description,
                'priority' => $priority,
                'estimated_hours' => $taskData['estimated_hours'] ?? null,
                'is_completed' => false
            ]);

            $createdTasks[] = [
                'id' => $task->id,
                'title' => $task->title,
                'priority' => $task->priority,
                'estimated_hours' => $task->estimated_hours,
                'phase' => $taskData['phase'] ?? 'Phase 1'
            ];
        }

        return $createdTasks;
    }

    /**
     * Build conversation log for UI display
     */
    private function buildConversationLog($planner, $executor, $reviewer, $coordinator)
    {
        return [
            [
                'agent' => 'Planner',
                'role' => 'Strategic Planning',
                'emoji' => '🎯',
                'summary' => $planner['analysis'] ?? 'Analyzed goal and created task breakdown'
            ],
            [
                'agent' => 'Executor',
                'role' => 'Execution Analysis',
                'emoji' => '⚡',
                'summary' => $executor['execution_strategy'] ?? 'Assessed feasibility and execution approach'
            ],
            [
                'agent' => 'Reviewer',
                'role' => 'Quality Assurance',
                'emoji' => '🔍',
                'summary' => "Quality score: " . ($reviewer['quality_score'] ?? 'N/A') . "/10 - Provided improvements"
            ],
            [
                'agent' => 'Coordinator',
                'role' => 'Final Synthesis',
                'emoji' => '🎭',
                'summary' => $coordinator['executive_summary'] ?? 'Created final optimized plan'
            ]
        ];
    }
}
