# WIS-CMS — Wesleyan International Society Church Management System

> A modern, full-stack Church Management System built for The Methodist Church Ghana — Wesleyan International Society.

![Laravel](https://img.shields.io/badge/Laravel-13-red?style=flat-square&logo=laravel)
![React](https://img.shields.io/badge/React-18-blue?style=flat-square&logo=react)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-blue?style=flat-square&logo=postgresql)
![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)

---

## 📋 About

WIS-CMS is a production-grade church administration platform designed to replace 
paper-based and informal processes with a secure, modern web application.

Built as a pro bono project for The Methodist Church Ghana — Wesleyan International Society, 
serving approximately 800–1,000 members.

---

## ✨ Features (Version 1.0)

- 👥 **Member Management** — Full member profiles, children's records, status tracking
- 📋 **Attendance Tracking** — Adult and children's services, absentee detection
- 💰 **Finance Module** — Tithes, offerings, expenses with full audit trail
- 🏛️ **Department Management** — Groups, ministries, and leadership assignment
- 👋 **Visitor Tracking** — First-timer registration and follow-up workflow
- 📱 **Communication** — SMS and email broadcasts via Arkesel (Ghana SMS gateway)
- 📊 **Dashboard & Reports** — Real-time analytics, PDF and Excel exports
- 🔐 **Role-Based Access** — 6 roles: Super Admin, Pastor, Secretary, Finance Officer, Department Leader, Usher

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13 (PHP 8.5) |
| Frontend | React 18 + Vite + Tailwind CSS v4 |
| Database | PostgreSQL 16 |
| Auth | Laravel Sanctum (SPA token auth) |
| Roles & Permissions | Spatie Laravel Permission |
| Activity Logging | Spatie Laravel Activitylog |
| PDF Export | Laravel DomPDF |
| Excel Export | OpenSpout |
| SMS Gateway | Arkesel API (Ghana) |
| Containerisation | Docker + Docker Compose |

---

## 🚀 Local Development Setup

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+
- Docker Desktop

### Installation

```bash
# Clone the repository
git clone https://github.com/yourusername/wis-cms.git
cd wis-cms

# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Copy environment file
cp .env.example .env
php artisan key:generate

# Start PostgreSQL via Docker
docker compose up -d

# Run migrations and seed the database
php artisan migrate --seed

# Start development servers
php artisan serve        # Terminal 1 — Laravel (port 8000)
npm run dev             # Terminal 2 — Vite (port 3000)
```

### Default Login