# TaskFlow

TaskFlow is a modern, professional task and project management platform designed to streamline collaboration and productivity. Built with a focus on user-friendly interfaces and real-time updates, it allows teams to manage projects and tasks efficiently through an intuitive Kanban board system.

## Features

- **User Authentication**: Secure registration and login system for users.
- **Project Management**: Create, edit, and delete projects with detailed descriptions.
- **Task Management**: Add, edit, delete, and assign tasks within projects.
- **Kanban Board**: Visualize tasks in three columns: To Do, In Progress, and Done, with drag-and-drop functionality for status updates.
- **Real-Time Updates**: AJAX-powered status changes and polling for live task updates.
- **Notifications**: Automatic notifications for task additions, edits, and deletions, with a notification bell in the UI.
- **Responsive Design**: Modern glassmorphism UI that works on desktop and mobile devices.
- **Multi-User Support**: Users can view all projects but only edit their own or assigned tasks.

## Tech Stack

- **Backend**: PHP with PDO for database interactions.
- **Database**: MySQL.
- **Frontend**: HTML5, CSS3, JavaScript (vanilla JS with AJAX).
- **Server**: Apache (via XAMPP for local development).
- **Styling**: Custom CSS with glassmorphism effects and animations.

## Installation Guide

### Prerequisites
- XAMPP (or any Apache server with PHP and MySQL).
- Git (for cloning the repository).

### Steps
1. **Clone the Repository**:
   ```
   git clone https://github.com/yosrekh/taskflow.git
   cd taskflow
   ```

2. **Set Up XAMPP**:
   - Start XAMPP and ensure Apache and MySQL are running.
   - Place the project folder in `C:/xampp/htdocs/taskflow` (or your XAMPP htdocs directory).

3. **Create the Database**:
   - Open phpMyAdmin (usually at `http://localhost/phpmyadmin`).
   - Create a new database named `taskflow_db`.
   - Import the SQL schema (if provided) or create the tables manually:
     ```sql
     CREATE TABLE users (
         id INT AUTO_INCREMENT PRIMARY KEY,
         name VARCHAR(255) NOT NULL,
         email VARCHAR(255) UNIQUE NOT NULL,
         password VARCHAR(255) NOT NULL,
         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
     );

     CREATE TABLE projects (
         id INT AUTO_INCREMENT PRIMARY KEY,
         user_id INT NOT NULL,
         title VARCHAR(255) NOT NULL,
         description TEXT,
         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
     );

     CREATE TABLE tasks (
         id INT AUTO_INCREMENT PRIMARY KEY,
         project_id INT NOT NULL,
         title VARCHAR(255) NOT NULL,
         description TEXT,
         priority ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
         status ENUM('Pending', 'In Progress', 'Completed') DEFAULT 'Pending',
         assigned_to INT,
         due_date DATE,
         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
         FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
     );

     CREATE TABLE notifications (
         id INT AUTO_INCREMENT PRIMARY KEY,
         user_id INT NOT NULL,
         message TEXT NOT NULL,
         is_read BOOLEAN DEFAULT FALSE,
         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
     );
     ```

4. **Configure Database Connection**:
   - Edit `includes/db.php` if needed to match your database credentials (default is root with no password for XAMPP).

5. **Access the Application**:
   - Open your browser and go to `http://localhost/taskflow`.
   - Register a new account or log in if you have existing users.

## Usage

### For Users
1. **Register/Login**: Create an account or log in to access the dashboard.
2. **Dashboard**: View all projects. Click "عرض المهام" (View Tasks) to see the Kanban board for a project.
3. **Managing Projects**: Project owners can add new projects, edit, or delete them.
4. **Managing Tasks**: Add tasks to projects, assign them to users, set priorities and due dates. Drag tasks between columns to update status.
5. **Notifications**: Check the bell icon for updates on task changes.

### For Developers
- **File Structure**:
  - `index.php`: Landing page.
  - `dashboard.php`: Main dashboard.
  - `login.php` / `register.php`: Authentication pages.
  - `projects/`: Project-related pages (add, edit, delete).
  - `tasks/`: Task-related pages (view, update status).
  - `includes/db.php`: Database connection.
  - `css/styles.css`: Global styles.
  - `js/main.js`: JavaScript utilities.
- **Adding Features**: Extend functionality by adding new PHP files, updating the database schema, and modifying the frontend as needed.
- **AJAX Endpoints**: Use files like `get-tasks.php`, `update-status.php` for API-like interactions.
- **Styling**: Customize the glassmorphism theme in CSS files.

## Database Schema

- **users**: Stores user information (id, name, email, password).
- **projects**: Stores project details (id, user_id, title, description).
- **tasks**: Stores task details (id, project_id, title, description, priority, status, assigned_to, due_date).
- **notifications**: Stores user notifications (id, user_id, message, is_read).

## Contributing

Contributions are welcome! Please follow these steps:
1. Fork the repository.
2. Create a new branch for your feature (`git checkout -b feature/new-feature`).
3. Commit your changes (`git commit -am 'Add new feature'`).
4. Push to the branch (`git push origin feature/new-feature`).
5. Create a Pull Request.

## License

This project is licensed under the MIT License. See the LICENSE file for details.
