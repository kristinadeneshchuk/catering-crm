# fail2ban для CRM

Захист входу від підбору пароля. Працює в парі з кодом:
`App\Listeners\LogFailedLogin` пише кожну невдалу спробу в `storage/logs/security.log`,
fail2ban читає цей файл і банить IP.

| Файл | Куди на сервері |
|---|---|
| `filter.d/crm-login.conf` | `/etc/fail2ban/filter.d/` |
| `action.d/crm-telegram.conf` | `/etc/fail2ban/action.d/` |
| `jail.d/crm-login.local` | `/etc/fail2ban/jail.d/` |
| `fail2ban.local` | `/etc/fail2ban/` |

Встановлення / оновлення:

    cp -r deploy/fail2ban/filter.d deploy/fail2ban/action.d deploy/fail2ban/jail.d /etc/fail2ban/
    cp deploy/fail2ban/fail2ban.local /etc/fail2ban/
    fail2ban-regex /var/www/horenko/storage/logs/security.log /etc/fail2ban/filter.d/crm-login.conf
    fail2ban-client reload

Корисне:

    fail2ban-client status crm-login              # хто зараз у бані
    fail2ban-client set crm-login unbanip <IP>    # розбанити (напр. менеджерку)
