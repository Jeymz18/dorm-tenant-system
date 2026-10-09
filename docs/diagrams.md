# System Diagrams

Paste any block below into [mermaid.live](https://mermaid.live) to preview it,
or view this file in VS Code with the "Markdown Preview Mermaid Support"
extension. GitHub also renders these natively if you push the repo there.

## Entity-Relationship Diagram

Reflects `database/schema.sql`. Note that **contracts** and **payments**
are two tables, not one — see the README's "Design decisions" section
for why.

```mermaid
erDiagram
    USERS ||--o| TENANTS : "has tenancy profile"
    DORM_ROOMS ||--o{ TENANTS : houses
    TENANTS ||--o{ CONTRACTS : signs
    DORM_ROOMS ||--o{ CONTRACTS : covers
    CONTRACTS ||--o{ PAYMENTS : "billed as"
    TENANTS ||--o{ PAYMENTS : makes
    TENANTS ||--o{ MAINTENANCE_REQUESTS : submits
    DORM_ROOMS ||--o{ MAINTENANCE_REQUESTS : needs
    USERS ||--o{ NOTIFICATIONS : sends
    USERS ||--o{ REPORTS : generates

    USERS {
        int user_id PK
        varchar first_name
        varchar last_name
        varchar email UK
        varchar password_hash
        enum role "admin | tenant"
        boolean is_active
    }
    DORM_ROOMS {
        int room_id PK
        varchar room_number UK
        varchar room_type
        int capacity
        decimal monthly_rate
        enum status
    }
    TENANTS {
        int tenant_id PK
        int user_id FK
        int room_id FK
        date checkin_date
        enum status
        boolean key_returned
        enum approval_status
    }
    CONTRACTS {
        int contract_id PK
        int tenant_id FK
        int room_id FK
        decimal monthly_rent
        date contract_start
        date contract_end
        enum contract_status
    }
    PAYMENTS {
        int payment_id PK
        int contract_id FK
        int tenant_id FK
        decimal payment_amount
        enum payment_status
    }
    MAINTENANCE_REQUESTS {
        int maintenance_id PK
        int tenant_id FK
        int room_id FK
        enum priority_level
        enum status
    }
    NOTIFICATIONS {
        int notification_id PK
        int sender_id FK
        enum type
        enum target_type
    }
    REPORTS {
        int report_id PK
        int generated_by FK
        enum report_type
    }
```

## Login & Role-Routing Flowchart

Matches the system flowchart in the original documentation, extended
with the tenant-approval branch that the prototype's Track Tenant
Status screen implies.

```mermaid
flowchart TD
    A([User visits the system]) --> B{Has an account?}
    B -- No --> C[Register — role is always set to tenant]
    C --> D[(users + tenants rows created,\napproval_status = Pending)]
    D --> E[Login page]
    B -- Yes --> E[Login page]
    E --> F[Enter email + password]
    F --> G{password_verify() OK?}
    G -- No --> H[Show "incorrect email or password"]
    H --> E
    G -- Yes --> I{is_active?}
    I -- No --> J[Show "account deactivated"]
    I -- Yes --> K{role?}
    K -- admin --> L[Admin Dashboard]
    K -- tenant --> M{approval_status?}
    M -- Pending / Rejected --> N[Show "application under review" screen]
    M -- Approved --> O{room_id assigned?}
    O -- No --> P[Dashboard: "waiting for room assignment"]
    O -- Yes --> Q[Full Tenant Dashboard]
```

## Payment & Contract Lifecycle

```mermaid
flowchart LR
    A[Admin creates Contract\nfor a room-assigned tenant] --> B[Tenant submits a Payment\n+ uploads receipt]
    B --> C[Payment status: Pending]
    C --> D{Admin verifies}
    D -- Looks good --> E[Payment status: Paid]
    D -- No payment received by due date --> F[cron/check_expirations.php\nsets status: Overdue]
    F --> G[Notification + email sent to tenant]
    E --> H[Shows in tenant's Payment History]
```
