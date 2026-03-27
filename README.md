# Road Transportation Infrastructure Management System (RTIM)
### Local Government Unit - Transportation Department

A web-based system for managing road transportation infrastructure, hazard reporting, maintenance scheduling, and traffic monitoring for the local community.



## Features

- **Resident Portal** – Report road hazards, track report status, pay tickets, and receive traffic alerts
- **Admin Dashboard** – Manage reports, assign teams, monitor projects, and publish advisories
- **Inspector Module** – Conduct inspections, issue OVR tickets, and submit hazard reports
- **Maintenance Module** – Manage assigned road, bridge, and traffic maintenance tasks
- **Treasurer Module** – Handle fund requests, budget tracking, and financial reports
- **Engineer Module** – Monitor infrastructure projects and inspection findings
- **Public Map** – View recent reports on an interactive map (Leaflet.js + OpenStreetMap)



## Tech Stack

- **Backend:** PHP (PDO + MySQL)
- **Frontend:** Bootstrap 5, Font Awesome, Leaflet.js
- **Email:** PHPMailer
- **PDF:** TCPDF
- **HTTP Client:** Guzzle
- **Database:** MySQL (`rtim`)
- **Server:** Apache (XAMPP / Docker)



## Requirements

- PHP 8.0+
- MySQL 5.7+
- Composer
- Apache with `mod_rewrite` enabled



## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/Berba-22002/lucia-road-trans-lgu.git
   ```

2. Move to your web server root (e.g., `htdocs` for XAMPP):
   ```bash
   cd lucia-road-trans-lgu
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Copy the environment file and configure it:
   ```bash
   cp .env.example .env
   ```

5. Import the database:
   ```bash
   mysql -u root -p rtim < rtim.sql
   ```

6. Configure your database in `config.php` or `.env`.

7. Visit `http://localhost/lucia-road-trans-lgu` in your browser.



## User Roles

| Role | Access |
|------|--------|
| Resident | Report hazards, view status, pay tickets |
| Admin | Full system management |
| Inspector | Conduct inspections, issue violations |
| Maintenance | View and update assigned tasks |
| Treasurer | Budget and financial management |
| Engineer | Project and inspection monitoring |



## License

© 2026 Road Transportation Management System. All rights reserved.  
Local Government Unit - Transportation Department
