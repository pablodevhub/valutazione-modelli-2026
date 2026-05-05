## Come usarla

### Installazione
1. Salva il file come `minidba.php` sul tuo web server con PHP 7.4+ e l'estensione PDO MySQL
2. Apri `https://tuoserver/minidba.php` nel browser
3. Accedi con le credenziali MariaDB (host, porta, utente, password)

### Funzionalità incluse

| Area | Operazioni |
|---|---|
| **Database** | Lista, crea (con charset/collation), drop |
| **Tabelle** | Lista, crea (editor colonne dinamico), browse con filtri/ordinamento/paginazione, insert, edit, delete, structure, indici, truncate, drop |
| **Viste** | Lista, crea, modifica, drop |
| **Stored Procedure** | Lista, crea, modifica, drop |
| **Funzioni** | Lista, crea, modifica, drop |
| **SQL** | Esecuzione query libera con risultati tabellari e tempo di esecuzione |
| **Backup** | Completo con gestione corretta di BLOB (hex `0x...`), date (`YYYY-MM-DD HH:MM:SS`), NULL, viste, procedure, funzioni; opzione compressione gzip |
| **Restore** | Da file `.sql` / `.sql.gz` uploadato o da testo incollato; parser SQL gestisce `DELIMITER`, stringhe, commenti |

### Backup automatizzato via GET

1. **Configura** nel file PHP (in cima):
   ```php
   define('BACKUP_API_KEY', 'la-tua-chiave-segreta-lunghissima');
   define('BACKUP_DB_USER', 'backup_user');
   define('BACKUP_DB_PASS', 'password_sicura');
   // Opzionale: limita IP
   define('BACKUP_ALLOWED_IPS', ['10.0.0.5', '192.168.1.0/24']);
   ```

2. **Crea un utente MariaDB dedicato** con permessi minimi:
   ```sql
   CREATE USER 'backup_user'@'localhost' IDENTIFIED BY 'password_sicura';
   GRANT SELECT, SHOW VIEW, EVENT, TRIGGER, LOCK TABLES ON *.* TO 'backup_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. **Automatizza con cron**:
   ```bash
   # Backup quotidiano alle 3:00
   0 3 * * * curl -s -o /backups/mydb_$(date +\%F).sql.gz "https://tuoserver/minidba.php?auto_backup=1&key=la-tua-chiave&db=mio_db&compress=1"
   
   # Salva direttamente sul server
   0 3 * * * curl -s "https://tuoserver/minidba.php?auto_backup=1&key=la-tua-chiave&db=mio_db&save=1&compress=1"
   ```

### Sicurezza

- **CSRF**: Token univoco su tutti i form POST
- **Auto-backup**: Autenticazione tramite API key con `hash_equals()` (anti timing-attack), whitelist IP opzionale, credenziali DB configurate lato server (non nel URL)
- **SQL injection**: Identificatori quotati con backtick, valori formattati per tipo (BLOB→hex, date→formato ISO, numeri→raw), prepared statements per DML
- **XSS**: Tutti gli output HTML escaped con `htmlspecialchars`
- **Sessione**: Timeout gestito, credenziali distrutte alla disconnessione
