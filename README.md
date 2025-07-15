# Link Manager

A PHP-based link collection and management tool with role-based access control (RBAC), group visibility, and flexible views.

---

## Features

- **Pages & Sections** — Organize links into pages and sections, with custom sort order.
- **Links** — Store name, description, URL, logo, background & text colors, and creator.
- **RBAC & Groups**  
  - **Admin** users see and manage everything.  
  - **Regular** users see only pages assigned to their groups and can manage all content on those pages.
- **Multi-view**  
  - **Card view**: Rich cards with logos.  
  - **Minimal view**: Compact list.  
  - **Tree view**: Full hierarchy.
- **Search** — Global search across all accessible pages.
- **CLI tools**  
  - **setup.php**: Initialize schema, roles, default admin and main page.  
  - **import.php**: Batch import links from CSV (creating missing pages/sections).

---

## Requirements

- PHP 8.0+  
- MySQL 5.7+ (with `pdo_mysql`)  
- PHP extensions: `pdo_mysql`, `yaml`  

---

## Installation

1. **Clone** the repo:  
   ```bash
   git clone https://github.com/curiousmaster/linkmanager.git
   cd linkmanager
   ```

2. **Configure**  
   Copy and edit `config.example.yml` to `config.yml` with your MySQL credentials:
   ```yaml
   database:
     driver: "mysql"
     host: "localhost"
     name: "links_db"
     username: "links_user"
     password: "secret"
   ```

3. **Initialize** the database schema and default admin:  
   ```bash
   php bin/setup.php -c config.yml
   ```
   You’ll be prompted to set a password for the default **admin** user.

---

## CLI: Batch Import

A simple CSV import script creates pages/sections if missing and inserts links.

**Syntax**  
```bash
php bin/import.php   -c config.yml   -f path/to/links.csv
```

**CSV Format** (header row required; columns in this order):  
```csv
page,section,name,description,url,logo,background,color
Main,Social,Twitter API,Official Twitter API docs,https://developer.twitter.com/logo.png,#1DA1F2,#fff
...
```

---

## Web Usage

1. **Login**:  
   - Default admin: `username: admin` + your chosen password.  
   - Other users can be created under **Manage Users** (admin only).

2. **Navigation**  
   - **Pages** dropdown (or sidebar) shows only those pages you may view/manage.  
   - **Search** box filters links across all accessible pages.  
   - **View buttons** switch between Card, Minimal, and Tree layouts.

3. **Managing Content**  
   - **Admins** can add/edit/delete pages, assign page-visibility groups, manage all sections & links.  
   - **Regular users** can add/edit/delete sections & links on pages assigned to their groups.

---

## Configuration and Deployment

- Deploy on any PHP-capable web server.  
- Point your document root to the project’s `htdocs/` (or adjust includes).  
- Ensure `bin/` scripts are executable (`chmod +x bin/*.php`).

---

