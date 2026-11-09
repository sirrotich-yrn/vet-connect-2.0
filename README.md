# VetConnect Kenya — XAMPP edition (PHP + MySQL)

## Set up (5 minutes)
1. Open the **XAMPP Control Panel** and **Start Apache** and **Start MySQL**.
2. Copy this whole folder into `C:\xampp\htdocs\` and name it `vetconnect`
   (so `C:\xampp\htdocs\vetconnect\index.html` exists).
3. In your browser open **http://localhost/vetconnect/install.php** — it creates the database
   `vetconnect`, all tables and the admin account. (Add `?demo=1` to also create sample vets and a farmer.)
4. Open **http://localhost/vetconnect/** and use the site.

Admin login: `admin@vetconnect.co.ke` / `admin123` (change it in `api/config.php` *before* running install.php).
Demo accounts (if you used `?demo=1`): otieno@vet.ke, aisha@vet.ke, kamau@vet.ke, wanjiru@farm.ke — password `password123`.

Alternative to step 3: in phpMyAdmin (http://localhost/phpmyadmin) click **Import** and choose `database.sql`.

## Settings — `api/config.php`
Database user/password (XAMPP default: `root` and empty password), the token secret, and `MATCH_RADIUS_KM` (default 60 km).

## How it works
- `api/index.php` is the whole backend (REST routes, login tokens, proximity matching with the Haversine formula).
- Live updates: each dashboard asks the server for new events every 3 seconds (new requests, accepts, chat messages, records). No extra software needed.
- Tables: users, animals, requests, messages, records, events.

## Requirements -> where
1. Farmer request (animal, location, problem) -> `POST /requests`; vets within the radius are notified.
2. Vet views/manages/responds -> `GET /requests`, `PATCH /requests/{id}` (accept, start, complete, release, schedule).
3. Farmer-vet communication -> `/requests/{id}/messages` (chat, appointments, treatment advice).
4. Admin records -> `/records` create / edit / delete / search (farmer, animal, diagnosis, treatment, follow-up).

## Troubleshooting
- "Setup failed": MySQL isn't started, or the password in `api/config.php` doesn't match.
- Blank dashboard / "Server error": check `C:\xampp\apache\logs\error.log`.
- "Use my location" only works on `http://localhost` or HTTPS — fine for local testing.
