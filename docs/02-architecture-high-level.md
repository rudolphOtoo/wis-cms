# High-Level Architecture

## Overview
The WIS-CMS operates on a decoupled client-server model. While Laravel serves the initial HTML wrapper, the entire frontend lifecycle is managed by React. Communication between the client and server occurs exclusively over HTTP via a JSON REST API.

## Architectural Flow

```mermaid
flowchart TD
    Client[Web Browser / Mobile Device]
    
    subgraph Frontend [React SPA (resources/js)]
        ReactApp[React App Initialization]
        Router[React Router 7]
        Context[React Context / Auth State]
        Axios[Axios Interceptors]
    end
    
    subgraph Backend [Laravel API (app/Http)]
        Sanctum[Sanctum Auth Middleware]
        RBAC[Spatie Permissions]
        Controllers[API Controllers]
        Models[Eloquent Models]
    end
    
    DB[(PostgreSQL 16 Database)]
    
    Client -- 1. Loads Initial Page --> ReactApp
    ReactApp -- 2. Route Navigation --> Router
    Router -- 3. Requires Data --> Context
    Context -- 4. Fetch JSON --> Axios
    Axios -- 5. HTTP Request w/ Bearer Token --> Sanctum
    Sanctum -- 6. Verify Identity --> RBAC
    RBAC -- 7. Verify Access --> Controllers
    Controllers -- 8. Business Logic --> Models
    Models -- 9. Read/Write Query --> DB
    DB -- 10. Return Data --> Controllers
    Controllers -- 11. JSON Response --> Axios
```

## Request Lifecycle (Deep Dive)
1. **Initial Load**: When a user navigates to the application, Laravel's `web.php` route catches the request and serves a single Blade view (`resources/views/app.blade.php` usually). This view includes the Vite-compiled JavaScript assets.
2. **Client-Side Rendering**: Once loaded, React takes complete control of the DOM. React Router determines which page to render based on the URL.
3. **API Interaction**: When a component (e.g., Member List) needs data, it triggers an Axios call.
4. **Token Authentication**: Axios automatically attaches the user's Sanctum Bearer token in the `Authorization` header.
5. **Route Protection**: The request hits Laravel's `api.php`. Middleware (`auth:sanctum`) verifies the token. Secondary middleware (`permission:view members`) checks if the authenticated user has the necessary Spatie roles to perform the action.
6. **Data Processing**: The Controller fetches data using Eloquent ORM. Models automatically enforce the `branch_id` scope to ensure data isolation. The data is serialized into JSON and returned to the React client.
