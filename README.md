# Kaleidoscope offline

Tento projekt obsahuje statickou offline kopii webu `https://kaleidoscope.mindmove.cz` ve složce `kaleidoscope-offline/`.

## Struktura složek

```text
kaleidoscope-offline/
├── index.html
├── assets/
│   ├── css/
│   ├── js/
│   └── img/
└── audio/
    ├── 1.mp3
    ├── 2.mp3
    ├── 3.mp3
    ├── 4.mp3
    ├── 5.mp3
    ├── 6.mp3
    └── 7.mp3
```

## Jak zkopírovat do telefonu

1. Přeneste celou složku `kaleidoscope-offline/` na telefon přes USB, cloud, AirDrop nebo přes počítač a aplikaci pro správu souborů.
2. Otevřete složku v telefonu a spusťte `index.html` v prohlížeči.

## Proč nemusí fungovat přes `file://`

Některé prohlížeče blokují přehrávání audio a některé AJAX/streaming požadavky, když je stránka otevřená přímo přes `file:///.../index.html`.

To je běžný limit prohlížeče. V takovém případě je nejjednodušší:

- spustit stránku přes lokální HTTP server
- nebo použít jednoduchou aplikaci pro lokální web server na Androidu

## Jak stránku spustit lokálně přes PHP built-in server

Na počítači v adresáři nad složkou `kaleidoscope-offline/` spusťte:

```bash
php -S 0.0.0.0:8000 -t kaleidoscope-offline
```

Potom otevřete ve webovém prohlížeči:

```text
http://localhost:8000/
```

## Jak to funguje na Androidu

Pokud je třeba stránku spouštět bez počítače, můžete použít jednoduchou aplikaci pro lokální web server, například:

- `Simple HTTP Server`
- `Web Server` / `HTTP Server` aplikace v obchodech Play Store
- nebo jakýkoliv jednoduchý server, který umístí složku do web-rootu a běží na místní IP adresě telefonu

V takovém případě otevřete v prohlížeči adresu typu:

```text
http://192.168.1.10:8000/
```

## Poznámka

Toto je statická offline verze: neobsahuje Composer, žádný framework, databázi ani serverovou aplikaci. Všechny potřebné soubory jsou uloženy lokálně v adresáři `assets/` a `audio/`.
