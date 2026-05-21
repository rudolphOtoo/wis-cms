# WIS-CMS: System Overview

## Introduction
The Wesleyan International Society Church Management System (WIS-CMS) is a modern, full-stack web application designed to transition church administration from paper-based tracking to a secure, digital platform. It is engineered primarily for The Methodist Church Ghana — Wesleyan International Society.

## Core Mission
The system aims to centrally manage a congregation of approximately 800–1,000 members. Its primary modules include:
- **Membership Management**: Detailed records, family associations (children), and membership statuses.
- **Visitor Tracking**: Capturing visitor details and conversion paths.
- **Department Coordination**: Grouping members into specific church groups (e.g., Men's Fellowship, Choir).
- **Future Expansions**: Attendance tracking, Financial logging, and internal communications via SMS.

## Technology Stack
The application is structured as a single-page application (SPA) layered over a robust RESTful API.

### Backend Stack
- **Framework**: Laravel (PHP 8.3+).
- **Database**: PostgreSQL 16 (optimized for relational integrity and UUIDs).
- **Authentication**: Laravel Sanctum (Token-based authentication for API security).
- **Authorization**: Spatie Laravel Permission for Role-Based Access Control (RBAC).
- **Auditing**: Spatie Activity Log for immutable transaction history.
- **Document Generation**: DomPDF and OpenSpout (integrated for future export capabilities).

### Frontend Stack
- **Library**: React 19.
- **Router**: React Router 7 for client-side navigation.
- **Build Tool**: Vite 8 for fast Hot Module Replacement (HMR) and optimized builds.
- **Styling**: Tailwind CSS v4 for rapid UI component construction.
- **Data Visualization**: Recharts for dashboard analytics.
- **HTTP Client**: Axios configured with request/response interceptors for token handling.

## Multi-Tenancy Strategy
From inception, WIS-CMS incorporates a branch-scoping multi-tenant paradigm. This means every critical entity in the database is tied to a specific `branch_id`. While currently deployed for a single congregation, this design guarantees that the system can horizontally scale to accommodate multiple branches across different geographical locations without architectural refactoring.
