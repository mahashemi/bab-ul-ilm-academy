# Deployment Process — Bab ul Ilm Academy

This document defines the **standard workflow** for shipping changes: local edit → test → commit → push to GitHub → deploy to server. Follow this every time, for every change, no matter how small.

Repo: https://github.com/mahashemi/bab-ul-ilm-academy

---

## 1. Local Development Loop

1. Edit files in `F:\Seyed_Mohammad_Abuzar_Hashemi_Projects\bab_ul_ilm_academy\`
2. Copy changed files to the XAMPP test copy: `C:\xampp\htdocs\bab_ul_ilm_academy\`
   (or edit directly in `htdocs` and skip the copy step — see note below)
3. Test in browser: `http://localhost/bab_ul_ilm_academy/`
4. If you changed `schema.sql`, re-import it:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root bab_ul_ilm < schema.sql
   ```
5. Confirm no PHP errors (check page renders, check for `Fatal error` / `Warning` text)

> **Recommended:** make `F:\...\bab_ul_ilm_academy` and `C:\xampp\htdocs\bab_ul_ilm_academy` the same folder via a symlink, so you never have to copy:
> ```powershell
> Remove-Item "C:\xampp\htdocs\bab_ul_ilm_academy" -Recurse -Force
> New-Item -ItemType SymbolicLink -Path "C:\xampp\htdocs\bab_ul_ilm_academy" -Target "F:\Seyed_Mohammad_Abuzar_Hashemi_Projects\bab_ul_ilm_academy"
> ```

---

## 2. Commit to Git (Local)

```bash
cd F:\Seyed_Mohammad_Abuzar_Hashemi_Projects\bab_ul_ilm_academy
git add -A
git status                     # review what's staged
git commit -m "Describe the change clearly, e.g. 'Add certificate generation on course completion'"
```

**Commit message rules:**
- One logical change per commit (don't bundle unrelated fixes)
- Imperative mood: "Add", "Fix", "Update" — not "Added"/"Fixed"
- Reference the TASKS.md item if applicable, e.g. "Implement lesson progress tracking (Phase 2)"

---

## 3. Push to GitHub

```bash
git push origin main
```

This is your backup and source of truth. **Never skip this step** — the server should never have code that isn't also on GitHub.

---

## 4. Deploy to Production Server

Two supported paths depending on what kind of hosting you end up with:

### Option A — Shared Hosting (cPanel / FTP, no SSH)
Most budget PHP/MySQL hosts (Hostinger, Namecheap, GoDaddy) fall here.

1. Log in to cPanel → File Manager (or use an FTP client like FileZilla)
2. Upload all files from this repo into `public_html/` (or a subdomain folder)
3. Go to cPanel → MySQL Databases → create a database + user, note credentials
4. Edit `config.php` on the **server** (not in git) with production DB credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'your_cpanel_db_user');
   define('DB_PASS', 'your_cpanel_db_password');
   define('DB_NAME', 'your_cpanel_db_name');
   define('SITE_URL', 'https://babulilmacademy.com');
   ```
5. Go to cPanel → phpMyAdmin → select your new database → Import → upload `schema.sql`
6. Visit `https://babulilmacademy.com` and verify it works
7. Enable SSL (cPanel → SSL/TLS Status → AutoSSL, usually free with Let's Encrypt)

> Repeat steps 2–4 every time you have new changes — re-upload only the changed files (FTP clients can sync just the diffs).

### Option B — VPS with SSH (DigitalOcean / Linode / AWS / etc.)
Once you have a server with SSH and Apache/Nginx + PHP + MySQL installed:

**One-time setup:**
```bash
ssh user@your-server-ip
cd /var/www
git clone https://github.com/mahashemi/bab-ul-ilm-academy.git
cd bab-ul-ilm-academy
cp config.php config.php.bak   # keep a reference
nano config.php                 # set production DB credentials + SITE_URL
mysql -u root -p < schema.sql   # or create DB first, then import
```
Point your web server's document root at `/var/www/bab-ul-ilm-academy`, enable SSL via certbot.

**Every subsequent deploy:**
```bash
ssh user@your-server-ip
cd /var/www/bab-ul-ilm-academy
git pull origin main
```
That's it — `git pull` syncs the server to whatever was last pushed to GitHub. If `schema.sql` changed, re-run the relevant `ALTER`/migration manually (this project does not yet use a migration tool — see Open Questions in TASKS.md).

---

## 5. Post-Deploy Checklist
- [ ] Homepage loads without errors
- [ ] Register a test account (both student and teacher roles)
- [ ] Login works for both roles
- [ ] Teacher can create a course and add a lesson
- [ ] Student can enroll and mark a lesson complete
- [ ] No PHP warnings/errors visible on any page
- [ ] `config.php` on the server has **production** credentials, never the local XAMPP defaults

---

## Quick Reference

| Step | Command |
|---|---|
| Stage + commit | `git add -A && git commit -m "message"` |
| Push | `git push origin main` |
| Pull on server (Option B) | `git pull origin main` |
| Re-import schema locally | `C:\xampp\mysql\bin\mysql.exe -u root bab_ul_ilm < schema.sql` |
