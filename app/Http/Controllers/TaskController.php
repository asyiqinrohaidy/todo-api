<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Task;

class TaskController extends Controller
{
    /**
     * Get all tasks with optional filtering
     */
    public function index(Request $request)
    {
        $query = $request->user()->tasks();

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'completed') {
                $query->where('is_completed', true);
            } elseif ($request->status === 'pending') {
                $query->where('is_completed', false);
            }
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->byPriority($request->priority);
        }

        // Filter by due date
        if ($request->has('due_filter')) {
            switch ($request->due_filter) {
                case 'overdue':
                    $query->overdue();
                    break;
                case 'today':
                    $query->dueToday();
                    break;
                case 'upcoming':
                    $query->upcoming();
                    break;
            }
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tasks = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $tasks
        ]);
    }

    /**
     * Create a new task
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'reminder_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
            'estimated_hours' => 'nullable|integer|min:1'
        ]);

        $task = $request->user()->tasks()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $task
        ], 201);
    }

    /**
     * Get a single task
     */
    public function show($id)
    {
        $task = auth()->user()->tasks()->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $task
        ]);
    }

    /**
     * Update a task
     */
    public function update(Request $request, $id)
    {
        $task = $request->user()->tasks()->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_completed' => 'sometimes|boolean',
            'due_date' => 'nullable|date',
            'reminder_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
            'estimated_hours' => 'nullable|integer|min:1'
        ]);

        $task->update($validated);

        return response()->json([
            'success' => true,
            'data' => $task
        ]);
    }

    /**
     * Delete a task
     */
    public function destroy($id)
    {
        $task = auth()->user()->tasks()->findOrFail($id);
        $task->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task deleted successfully'
        ]);
    }

    /**
     * Get task statistics
     */
    public function stats(Request $request)
    {
        $user = $request->user();

        $stats = [
            'total' => $user->tasks()->count(),
            'completed' => $user->tasks()->where('is_completed', true)->count(),
            'pending' => $user->tasks()->where('is_completed', false)->count(),
            'overdue' => $user->tasks()->overdue()->count(),
            'due_today' => $user->tasks()->dueToday()->count(),
            'high_priority' => $user->tasks()->byPriority('high')->where('is_completed', false)->count(),
            'total_hours_estimated' => $user->tasks()->where('is_completed', false)->sum('estimated_hours')
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}