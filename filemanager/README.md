## Note per il deployment

1. **Posiziona tutti i file** nella root del progetto web (document root di Apache).

2. **Abilita `mod_authz_core`** in Apache (generalmente già attivo):
   ```bash
   a2enmod authz_core
   ```

3. **Abilita `mod_rewrite`** se non attivo (non strettamente necessario per questa versione, ma utile):
   ```bash
   a2enmod rewrite
   ```

4. **Assicurati che PHP 8+** sia configurato con l'estensione `zip` abilitata (`php -m | grep zip`). Se manca: `apt install php-zip` o aggiungi `extension=zip` in `php.ini`.

5. **Permessi**: l'utente Apache (`www-data`) deve avere permessi di lettura/scrittura sulle cartelle elencate in `ALLOWED_FOLDERS`.

6. **Modifica il file `.env`** con credenziali reali e i percorsi effettivi delle cartelle che vuoi gestire.

7. **Accesso**: apri il browser verso `http://your-server/`, inserisci le credenziali, seleziona la cartella e gestisci i file.
