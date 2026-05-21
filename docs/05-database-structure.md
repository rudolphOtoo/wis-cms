# Database Structure

WIS-CMS relies on PostgreSQL 16. The schema is highly normalized and utilizes **UUID primary keys** for enhanced security (preventing ID enumeration) and better support for distributed databases in the future.

## Entity Relationship Diagram

```mermaid
erDiagram
    BRANCH {
        uuid id PK
        string name
        string location
    }
    
    USER {
        uuid id PK
        uuid branch_id FK
        string name
        string email
    }
    
    MEMBER {
        uuid id PK
        uuid branch_id FK
        string member_number
        string first_name
        string last_name
        string gender
        date date_of_birth
    }
    
    VISITOR {
        uuid id PK
        uuid branch_id FK
        string first_name
        string phone
    }
    
    CHILDREN {
        uuid id PK
        uuid member_id FK
        string name
        date date_of_birth
    }
    
    DEPARTMENT {
        uuid id PK
        uuid branch_id FK
        string name
        uuid leader_id FK
    }
    
    DEPARTMENT_MEMBER {
        uuid department_id FK
        uuid member_id FK
    }
    
    FINANCE_CATEGORY {
        uuid id PK
        uuid branch_id FK
        string name
        string type "income/expense"
    }
    
    TRANSACTION {
        uuid id PK
        uuid branch_id FK
        uuid finance_category_id FK
        uuid member_id FK
        decimal amount
    }
    
    SERVICE_TYPE {
        uuid id PK
        uuid branch_id FK
        string name
    }
    
    ATTENDANCE_SESSION {
        uuid id PK
        uuid branch_id FK
        uuid service_type_id FK
        date session_date
    }
    
    ATTENDANCE_RECORD {
        uuid id PK
        uuid session_id FK
        uuid member_id FK
        string status
    }

    BRANCH ||--o{ USER : "employs"
    BRANCH ||--o{ MEMBER : "registers"
    BRANCH ||--o{ VISITOR : "welcomes"
    BRANCH ||--o{ DEPARTMENT : "has"
    BRANCH ||--o{ FINANCE_CATEGORY : "tracks"
    BRANCH ||--o{ SERVICE_TYPE : "holds"
    
    MEMBER ||--o{ CHILDREN : "has"
    MEMBER ||--o{ DEPARTMENT_MEMBER : "joins"
    DEPARTMENT ||--o{ DEPARTMENT_MEMBER : "contains"
    
    MEMBER ||--o{ TRANSACTION : "makes"
    FINANCE_CATEGORY ||--o{ TRANSACTION : "categorizes"
    
    SERVICE_TYPE ||--o{ ATTENDANCE_SESSION : "defines"
    ATTENDANCE_SESSION ||--o{ ATTENDANCE_RECORD : "records"
    MEMBER ||--o{ ATTENDANCE_RECORD : "logged in"
```

## Table Deep Dive

### Core Entities
- **branches**: The absolute foundation of the multi-tenancy. Every subsequent record points back here via `branch_id`.
- **users**: System administrators and staff. Linked to a branch and assigned roles via Spatie's polymorphic tables.
- **members**: The largest and most complex dataset. Tracks detailed demographic data, contact info, and marital status. Features Laravel's `SoftDeletes` to preserve historical integrity if a member leaves.
- **visitors**: Similar to members but lightweight. The system supports a conversion mechanism to transition a visitor to a full member.
- **children**: Built with a one-to-many relationship with members to track family units for the children's ministry.

### Organizational Groupings
- **departments**: e.g., Choir, Ushers, Media. Includes a `leader_id` pointing to a user or member.
- **department_members**: A pivot table establishing a robust many-to-many relationship between departments and members.

### Operational Data (Planned / In-Progress)
- **finance_categories & transactions**: Facilitates double-entry-like bookkeeping for church finances, categorizing things like Tithes, Welfare, and Offertory into discrete transactions.
- **service_types, attendance_sessions, attendance_records**: A highly structured hierarchical schema to accurately track exactly who attended which specific service (e.g., Sunday Service vs. Friday Night Prayer) on a given date.
- **messages & message_recipients**: The underlying architecture designed to eventually support mass SMS and Email broadcasts.
