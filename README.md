# TaskFlow - Modern Project & Task Management App

TaskFlow is a modern, responsive project and task management web application built with PHP, PDO, and MySQL. It features a professional dashboard, Kanban-style task board, user authentication, collaborative project management, and a beautiful glassmorphism-inspired UI with RTL (Arabic) support.

## Features

- **User Authentication**: Secure login, registration, and logout for all users.
- **Dashboard**: View all projects (for all users), with project owner display and modern card layout.
- **Project Management**: Add, edit, and delete projects. Project actions use stylish SVG icons.
- **Task Management**: 
  - Kanban board (To Do, In Progress, Done) with drag-and-drop-like status change.
  - Add, edit, and delete tasks via a popup form.
  - Assign tasks to any user (dynamic user list from DB).
  - Priority icons and color coding for tasks (High, Medium, Low).
  - Instant status update with AJAX and notification.
  - Responsive, animated, and RTL-friendly UI.
- **Popup Forms**: Elegant, animated, and responsive popup for adding/editing tasks, with ESC and click-to-close support.
- **Glassmorphism & Animations**: Modern CSS for a professional look and feel.

## Technologies Used

- **PHP** (Vanilla, no frameworks)
- **PDO** for secure database access
- **MySQL** (see `db/taskflow_db.sql` for schema)
- **HTML5 & CSS3** (custom, glassmorphism, responsive, RTL)
- **JavaScript** (vanilla, for popups, AJAX, and UI interactions)
- **SVG Icons** (inline, for all actions)

## Key Implementation Details

- **Kanban Board**: Tasks are grouped by status and displayed in columns. Status changes are handled via AJAX, instantly moving the card and updating the DB.
- **Popup Forms**: Add/edit task forms are shown in a modal popup, which is fully responsive and closes on ESC or outside click.
- **Dynamic User Assignment**: The task assignment dropdown is populated from the users table.
- **RTL & Arabic Support**: All layouts, forms, and UI elements are RTL-friendly and support Arabic text.
- **Security**: All user input is sanitized, and all DB access uses prepared statements.

## File Structure
- `login.php` - The Main Login Page
- `logout.php` - The Main Logout Page
- `register.php' - The Main Page For Adding A New Users
- `dashboard.php` - Main dashboard, project list, and actions
- `tasks/view-tasks.php` - Kanban board for tasks, popup forms
- `tasks/update-status.php` - Change Statuse Of The Task From (to do- in progress - done)
- `projects/` - Project add/edit/delete pages
- `css/styles.css` - All custom styles
- `js/main.js` - JavaScript for popups and UI
- `includes/db.php` - PDO DB connection
- `db/taskflow_db.sql` - Database schema

## How to Run

1. Import the database from `db/taskflow_db.sql` into your MySQL server.
2. Configure your DB credentials in `includes/db.php`.
3. Place the project in your web server root (e.g., `htdocs` for XAMPP).
4. Open `index.php` in your browser and register a new user.

---

**TaskFlow** is designed for teams and individuals who want a beautiful, modern, and collaborative way to manage projects and tasks in Arabic or English.
