# Backend Architecture

The backend operates on **Laravel**, strictly functioning as a JSON API provider for the frontend application.

## Directory Structure
- `app/Http/Controllers/Api/`: Contains all business logic controllers serving JSON.
- `app/Models/`: Eloquent ORM models representing database tables.
- `database/migrations/`: Schema definitions and table structures.
- `database/seeders/`: Initial data population (Roles, Super Admin, Finance Categories).
- `routes/api.php`: API endpoint definitions and route groupings.

## Authentication & Authorization
Security is paramount in the WIS-CMS architecture, separated into two robust layers:
- **Laravel Sanctum (Authentication)**: Handles authentication via Bearer tokens. When a user logs in, Sanctum issues a token that the frontend stores.
- **Spatie Permission (Authorization)**: Handles RBAC (Role-Based Access Control). Six core roles exist (Super Admin, Pastor, Secretary, Finance Officer, Department Leader, Usher).
- **Middleware Integration**: Routes are chained with middleware. For example, `Route::middleware(['auth:sanctum', 'permission:delete members'])` ensures only authenticated users with specific explicit rights can trigger a data deletion endpoint.

## Multi-Tenancy & Data Scoping
To support a multi-branch environment, data isolation is enforced at the model level.
- Nearly all entities (Members, Visitors, Transactions) include a `branch_id` foreign key.
- While currently managed in controllers, the architecture is primed for Global Scopes. A global scope would automatically append `WHERE branch_id = ?` to every database query, making cross-tenant data leakage practically impossible on a system level.

## Auditing and Logging
Accountability is critical for church administration, particularly regarding membership edits and financial transactions.
- **Spatie Activitylog**: Attached to critical models via the `LogsActivity` trait.
- Whenever a model is created, updated, or deleted, an immutable record is automatically stored in the `activity_log` table, detailing who made the change, when it occurred, and the exact state differences (old values vs. new values).

## API Controllers
Controllers (e.g., `MemberController`) strictly follow RESTful conventions (`index`, `show`, `store`, `update`, `destroy`). They are currently responsible for:
1. Validating incoming requests.
2. Applying business logic (e.g., generating `WIS-YYYY-####` member numbers).
3. Querying the database using Eloquent.
4. Returning standardized JSON responses.
