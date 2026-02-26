# Athena (AI-Powered Task Manager) - Backend API

Laravel-based REST API powering an intelligent task management system with AI collaboration, document parsing, and smart analysis.

![Laravel](https://img.shields.io/badge/Laravel-10-red)
![PHP](https://img.shields.io/badge/PHP-8.x-blue)
![AI](https://img.shields.io/badge/AI-OpenAI-green)

## Features

### REST API
- Full CRUD operations for tasks
- OAuth2 authentication (Laravel Passport)
- Task filtering and search
- Priority and time estimation
- Due date tracking

### AI Integration
- **Conversational AI** - Natural language task management
- **Multi-Agent System** - 4 specialised AI agents collaborate
- **Smart Analysis** - Auto-detects priority and estimates time
- **Document Intelligence** - OCR parsing of PDFs, images, Word docs
- **Function Calling** - Structured AI responses

### Architecture
- RESTful API design
- OAuth2 token authentication
- Optimised database queries
- Comprehensive error handling
- Request validation
- API logging

## Tech Stack

- **Framework:** Laravel 10
- **Language:** PHP 8.x
- **Database:** MySQL
- **Authentication:** Laravel Passport (OAuth2)
- **AI:** OpenAI GPT-4o-mini
- **OCR:** Tesseract OCR
- **PDF Parser:** Smalot/PdfParser
- **Word Parser:** PhpOffice/PhpWord

## Prerequisites

- PHP 8.1+
- Composer
- MySQL 8.0+
- OpenAI API Key

## Installation

1. **Clone the repository:**
```bash
git clone https://github.com/asyiqinrohaidy/todo-api.git
cd todo-api
```

2. **Install dependencies:**
```bash
composer install
```

3. **Configure environment:**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Update `.env` with your credentials:**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=todo_db
DB_USERNAME=root
DB_PASSWORD=

OPENAI_API_KEY=sk-proj-YOUR_KEY_HERE
```

5. **Run migrations:**
```bash
php artisan migrate
```

6. **Install Laravel Passport:**
```bash
php artisan passport:install
```

7. **Start the server:**
```bash
php artisan serve
```

API will be available at `http://127.0.0.1:8000`

## API Endpoints

### Authentication
```http
POST /api/register
POST /api/login
POST /api/logout
```

### Tasks
```http
GET    /api/tasks              # List all tasks
POST   /api/tasks              # Create task
GET    /api/tasks/{id}         # Get task
PUT    /api/tasks/{id}         # Update task
DELETE /api/tasks/{id}         # Delete task
PATCH  /api/tasks/{id}/toggle  # Toggle completion
```

### AI Features
```http
POST /api/ai/chat                    # Conversational AI
POST /api/ai/analyse-task            # Smart priority analysis
POST /api/documents/analyse          # Document OCR parsing
POST /api/multi-agent/process        # Multi-agent planning
```

## API Documentation

### Create Task
```http
POST /api/tasks
Authorisation: Bearer {token}
Content-Type: application/json

{
  "title": "Complete project report",
  "description": "Detailed analysis required",
  "due_date": "2026-03-01",
  "priority": "high",
  "estimated_hours": 4
}
```

### AI Chat
```http
POST /api/ai/chat
Authorisation: Bearer {token}
Content-Type: application/json

{
  "message": "Add a task to buy groceries tomorrow",
  "conversation_history": []
}
```

### Multi-Agent Processing
```http
POST /api/multi-agent/process
Authorisation: Bearer {token}
Content-Type: application/json

{
  "goal": "Launch a mobile app in 3 months",
  "context": "First-time app developer"
}
```

## Multi-Agent System

The system uses 4 specialised AI agents that collaborate:

1. **Planner Agent** 
   - Breaks down high-level goals into tasks
   - Estimates timelines
   - Identifies dependencies

2. **Executor Agent**
   - Analyses execution feasibility
   - Identifies blockers
   - Suggests quick wins

3. **Reviewer Agent** 
   - Quality checks the plan
   - Suggests improvements
   - Ensures best practices

4. **Coordinator Agent**
   - Synthesises all inputs
   - Makes final decisions
   - Creates optimised plan

## Project Structure
```
todo-api/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── AuthController.php        # Authentication
│   │       ├── TaskController.php        # Task CRUD
│   │       ├── AIController.php          # AI chat & analysis
│   │       ├── DocumentController.php    # Document parsing
│   │       └── MultiAgentController.php  # Multi-agent system
│   └── Models/
│       ├── User.php
│       └── Task.php
├── database/
│   └── migrations/
├── routes/
│   └── api.php                           # API routes
└── .env.example
```

## Security

- OAuth2 token authentication
- Request validation
- SQL injection protection (Eloquent ORM)
- XSS protection
- CORS configuration
- Environment variable secrets

## Testing
```bash
php artisan test
```

## Database Schema

### Users Table
- id, name, email, password, timestamps

### Tasks Table
- id, user_id, title, description
- is_completed, priority, estimated_hours
- due_date, created_at, updated_at

## Roadmap

- [ ] Task categories and tags
- [ ] Recurring tasks
- [ ] Task sharing/collaboration
- [ ] Email notifications
- [ ] Webhooks
- [ ] Rate limiting
- [ ] API versioning

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License.

## Author

**Asyiqin Rohaidy** - AI Engineer at Fulkrum Interactive

- GitHub: [@asyiqinrohaidy](https://github.com/asyiqinrohaidy)
- LinkedIn: [Your LinkedIn](https://linkedin.com/in/yourprofile)

## Acknowledgments

- OpenAI for GPT-4o-mini API
- Laravel community
- Tesseract OCR project
