# Athena - Backend API

> Full-stack AI task management system powered by Laravel 11 and LLaMA 3.2 

![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![LLaMA](https://img.shields.io/badge/LLaMA-3.2-00A67E?style=for-the-badge)

## Features

### AI-Powered Intelligence
- **Local LLaMA 3.2 Integration** - Zero API costs, complete data privacy
- **Smart Task Analysis** - Automatic priority and time estimation
- **Natural Language Chat** - Conversational task management
- **Multi-Agent System** - 5 specialised AI agents for complex goal breakdown
- **Document OCR** - Extract tasks from uploaded documents

### Hybrid Architecture
- **Fast Regex Commands** - < 1 second response time (90% of interactions)
- **Intelligent AI Processing** - 3-5 seconds when AI adds value (10% of interactions)
- **Automatic Fallback** - Rule-based backup if AI is unavailable
- **Zero Timeouts** - Production-ready reliability

### Smart Priority Detection
- **Keyword Recognition** - "urgent", "asap", "critical" → HIGH priority
- **Due Date Urgency** - Automatic priority based on deadlines
  - 0-2 days: HIGH
  - 3-7 days: MEDIUM
  - 8+ days: LOW
- **User Intent Override** - Explicit keywords always win

### Security & Performance
- **Laravel Sanctum** - Secure API authentication
- **CORS Support** - Cross-origin resource sharing configured
- **Comprehensive Error Handling** - Graceful degradation
- **Detailed Logging** - Full request/response tracking

---

## Tech Stack

| Technology | Version | Purpose |
|-----------|---------|---------|
| Laravel | 11.x | Backend framework |
| PHP | 8.2+ | Programming language |
| MySQL | 8.0+ | Database |
| LLaMA | 3.2 | Local AI model |
| Ollama | Latest | LLM serving platform |
| Laravel Sanctum | 4.x | API authentication |

---

## Prerequisites

Before installation, ensure you have:

- PHP 8.2 or higher
- Composer (latest version)
- MySQL 8.0 or higher
- Ollama (for local LLaMA 3.2)
- Git

---

## Installation

### Clone the Repository
```bash
git clone https://github.com/asyiqinrohaidy/todo-api.git
cd todo-api
```

### Install PHP Dependencies
```bash
composer install
```

### Environment Configuration
```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### Configure Database

Update `.env` with your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=todo_app
DB_USERNAME=root
DB_PASSWORD=
```

Create the database:
```sql
CREATE DATABASE todo_app;
```

### Run Migrations
```bash
php artisan migrate
```

### Install & Configure Ollama

**Windows:**
```bash
# Install Ollama
winget install Ollama.Ollama

# Pull LLaMA 3.2 model (2GB download)
ollama pull llama3.2

# Start Ollama server (keep this running)
ollama serve
```

**Mac/Linux:**
```bash
# Install Ollama
curl -fsSL https://ollama.com/install.sh | sh

# Pull LLaMA 3.2 model
ollama pull llama3.2

# Ollama runs automatically as a service
```

### Start the Server
```bash
php artisan serve
```

**API will be available at:** `http://127.0.0.1:8000`

---

## API Endpoints

### Authentication

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| POST | `/api/register` | Register new user | No |
| POST | `/api/login` | Login user | No |
| POST | `/api/logout` | Logout user | Yes |

**Example - Register:**
```bash
curl -X POST http://127.0.0.1:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

### Tasks

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| GET | `/api/tasks` | Get all tasks | Yes |
| POST | `/api/tasks` | Create task | Yes |
| PUT | `/api/tasks/{id}` | Update task | Yes |
| DELETE | `/api/tasks/{id}` | Delete task | Yes |

**Example - Create Task:**
```bash
curl -X POST http://127.0.0.1:8000/api/tasks \
  -H "Authorisation: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Deploy to production",
    "due_date": "2024-03-27",
    "priority": "high"
  }'
```

### AI Features

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| POST | `/api/ai/chat` | Natural language chat | Yes |
| POST | `/api/ai/analyze` | Analyse task | Yes |
| POST | `/api/ai/analyze-document` | Extract tasks from document | Yes |
| POST | `/api/multi-agent/process` | Multi-agent goal processing | Yes |

**Example - AI Chat:**
```bash
curl -X POST http://127.0.0.1:8000/api/ai/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "create task to read book tomorrow"
  }'
```

---

## AI Architecture

### Hybrid Approach
```
┌─────────────────────────────────────────────────────────┐
│                  USER REQUEST                           │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
              ┌──────────────────────┐
              │  Pattern Detection   │
              │  (Regex - Instant)   │
              └──────────────────────┘
                         │
           ┌─────────────┴─────────────┐
           │                           │
           ▼                           ▼
    ┌─────────────┐           ┌──────────────┐
    │  FAST PATH  │           │   AI PATH    │
    │  (< 1 sec)  │           │  (3-5 sec)   │
    └─────────────┘           └──────────────┘
           │                           │
    - Create task              - Task analysis
    - Delete task              - Document OCR
    - Complete task            - Multi-agent
    - List tasks               - Priority prediction
    - Get stats
```

### When LLaMA IS Used
-  **Task Analysis**: Predict priority and estimated hours
-  **Document OCR**: Extract structured tasks from unstructured text
-  **Multi-Agent Processing**: Break down complex goals

### When LLaMA is NOT Used
-  **Chat Commands**: Fast regex pattern matching
-  **Conversations**: Pre-written responses
-  **CRUD Operations**: Direct database queries

**Result:** 90% instant responses, 10% intelligent AI processing

---

## Chat Commands

The AI chat understands natural language:

### Create Tasks
```
 "create task to read book tomorrow"
 "can you add shopping task which is due today"
 "make urgent deploy task"
 "new meeting task next week"
```

### Delete Tasks
```
 "delete all tasks"
 "delete read book"
 "delete completed tasks"
```

### Complete Tasks
```
 "complete read book"
 "mark shopping as done"
 "finish workout task"
 "done with meeting"
```

### List & Stats
```
 "list my tasks"
 "show my pending tasks"
 "how many tasks do I have?"
 "what's my progress?"
```

### Bonus Features
```
 "show completed tasks"
 "reopen task read book"
 "mark task as incomplete"
```

---

## Priority Detection Examples

| Input | Detected Priority | Reason |
|-------|------------------|---------|
| "create task deploy tomorrow" | **HIGH** | Due in 1 day |
| "create urgent task read book next week" | **HIGH** | "urgent" keyword overrides |
| "create low priority task meeting tomorrow" | **LOW** | "low priority" keyword overrides |
| "create task groceries" | **MEDIUM** | No due date, no keywords |
| "create task fix bug in 10 days" | **LOW** | Due in 10 days |

---

## Performance Metrics

| Metric | Before (OpenAI) | After (Hybrid) | Improvement |
|--------|-----------------|----------------|-------------|
| Chat Commands | 60+ seconds | < 1 second | **60x faster** |
| Task Creation | 60+ seconds | < 1 second | **60x faster** |
| Task Analysis | 15-20 seconds | 3-5 seconds | **4x faster** |
| Timeout Rate | ~50% | 0% | **100% reliable** |
| API Costs | $0.15/1M tokens | $0 | **Free forever** |

---

## 🔧 Configuration

### Ollama Settings

Update `app/Http/Controllers/AIController.php` if needed:
```php
private $ollamaUrl = 'http://localhost:11434/api/generate';
private $ollamaModel = 'llama3.2';
```

### CORS Configuration

CORS is handled via `corsResponse()` helper in controllers. Modify headers in:
```php
private function corsResponse($data, $status = 200)
{
    return response()->json($data, $status)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
        ->header('Access-Control-Allow-Credentials', 'true');
}
```

---

## Troubleshooting

### Ollama Connection Issues

**Error:** "Connection refused to localhost:11434"

**Solution:**
```bash
# Check if Ollama is running
ollama list

# Start Ollama server
ollama serve
```

### Migration Errors

**Error:** "SQLSTATE[HY000] [1045] Access denied"

**Solution:**
```bash
# Check MySQL is running
# Update .env with correct credentials
php artisan migrate:fresh
```

### CORS Errors

**Error:** "CORS policy: No 'Access-Control-Allow-Origin' header"

**Solution:**
- Ensure `corsResponse()` is used in all controller methods
- Check OPTIONS route in `routes/api.php`

---

## Project Structure
```
todo-api/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── AIController.php         # AI chat & analysis
│   │       ├── AuthController.php       # Authentication
│   │       ├── TaskController.php       # CRUD operations
│   │       └── MultiAgentController.php # Multi-agent system
│   └── Models/
│       ├── User.php
│       └── Task.php
├── routes/
│   └── api.php                          # API endpoints
├── config/
│   ├── cors.php                         # CORS config
│   └── database.php                     # Database config
└── .env                                 # Environment variables
```

---

## Deployment

### Production Checklist

-  Set `APP_ENV=production` in `.env`
-  Set `APP_DEBUG=false` in `.env`
-  Configure production database
-  Run `composer install --optimize-autoloader --no-dev`
-  Run `php artisan config:cache`
-  Run `php artisan route:cache`
-  Set up proper CORS origins (remove `*`)
-  Configure HTTPS
-  Set up queue workers for background jobs

---

## Author

**Asyiqin Rohaidy**  
AI Engineer at Fulkrum Interactive

- GitHub: [@asyiqinrohaidy](https://github.com/asyiqinrohaidy)
- LinkedIn: [@asyiqinrohaidy](https://www.linkedin.com/in/asyiqinrohaidy/)

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

---

## 📄 License

This project is open source and available under the [MIT License](LICENSE).

---

## Acknowledgments

- **Laravel** - The PHP framework for web artisans
- **Ollama** - Running LLMs locally made easy
- **Meta AI** - LLaMA 3.2 language model
- **Fulkrum Interactive** - Project sponsor

---

## Support

If you have any questions or run into issues:

1. Check the [Troubleshooting](#-troubleshooting) section
2. Open an issue on GitHub
3. Contact: [your-email@example.com]

---

**Made with ❤️ by Asy | Powered by LLaMA 3.2**
