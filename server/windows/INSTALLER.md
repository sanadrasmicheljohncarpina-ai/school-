# Windows/XAMPP installer

Run PowerShell as Administrator:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\install-school-evaluation.ps1
```

For a non-default XAMPP directory:

```powershell
.\install-school-evaluation.ps1 -XamppRoot 'D:\xampp' -InstallPath 'D:\xampp\htdocs\index'
```

The installer checks XAMPP, downloads the repository's main branch, backs up an existing install directory, copies the PHP application, writes a local `.env.php`, creates the missing `shared/app_config.php` helper if needed, and can import a trusted SQL dump you provide.

## Important audit notes

The current public repository's deployment guide refers to `evaluation.sql` and `.env.example.php`, but neither file is present at the repository root. The installer therefore asks for a trusted existing SQL dump; it does not fabricate a database schema. You can skip the import with `-SkipDatabaseImport` and restore your database separately.

This installs a local XAMPP copy. It does not configure public DNS, router forwarding, or HTTPS. Do not expose MySQL port 3306 to the internet. Back up real school data before any migration.
