# Pregled e-računa (UBL 2.1)

Jednostavna PHP web aplikacija za **pregled i vizualizaciju e-računa (UBL 2.1 XML)** dokumenata.  
Aplikacija omogućuje čitanje osnovnih podataka, stavki računa te preuzimanje izvornog PDF-a (ako je uključen kao Base64).
Napravljena za one ljene koji nemaju ERP na mobitelu ili imaju licencu za samo jedno računalo i neda im se ić na 2. kat pogledati kaj to za fakturu je.
Vajbkodana s ChatGPTjem

## Funkcionalnosti
- Učitavanje UBL 2.1 XML e-računa
- Prikaz:
  - kupca i dobavljača
  - zaglavlja računa
  - stavki i iznosa
- Automatsko prepoznavanje Base64 PDF-a
- Preuzimanje PDF-a iz XML-a (ako je embedani)
- Jednostavan HTML prikaz bez baze podataka
- Dodani 2D barcode HUB3 za brže plaćanje putem aplikacije
- Single file riješenje

## Tehnologije
- PHP 8+
- HTML / CSS
- UBL 2.1 XML

## Pokretanje
1. Kloniraj repozitorij:
   ```bash
   git clone https://github.com/dklobucaric/pregled-e-racuna.git
2. Postavi projekt u web root (Apache / Nginx)
3. Otvori u browseru: http://localhost/pregled-e-racuna

Nema potrebe za bazom podataka niti dodatnim ekstenzijama.

## Screenshots
![Uploadaj XML](screenshots/pocetni.jpg)

![Human readable](screenshots/pregledaj.jpg)

## Live site
https://pregled-e-racuna.dd-lab.hr/

## Licenca
Koristi, mijenjaj i dijeli kako god želiš.  
Cilj projekta je da ti **olakša život**, ništa više, ništa manje.

Detalji su u [LICENSE](LICENSE) datoteci.

— Dalibor Klobučarić

## ToDo
1. Napraviti android app koji će po defaultu otvarati XML iz (g)mail app-a
2. Print button 
3. Learn how to vibe code better