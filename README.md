# BS Bootsschule Booking

Kombi-Buchung für bootsschule-berlin.de: Kunden wählen pro Kurs (z.B. SBF Binnen + SBF See) einen Termin und kaufen alles als ein WooCommerce-Produkt.

## Woher kommen die Termine?

Seit v2.0 **direkt aus Amelia** (nur lesend). Termine werden ausschließlich in Amelia gepflegt, die frühere eigene Terminverwaltung ("Kombi-Produkte") gibt es nicht mehr.

Angezeigt werden Amelia-Events, die
- den Status "approved" haben (abgesagte fallen raus),
- noch nicht begonnen haben,
- deren Buchungsschluss (falls gesetzt) nicht erreicht ist.

Mehrtägige Events (ein Zeitraum über mehrere Tage oder mehrere Zeiträume) werden automatisch als "Sa. 17.10.2026 + So. 18.10.2026" angezeigt.

Amelia speichert Zeiten in UTC, das Plugin rechnet in die WordPress-Zeitzone um. Falls eine Installation abweicht: Filter `bs_booking_amelia_times_are_utc` auf `false`.

## Einrichtung am Produkt

Box "Bootsschule Booking" im Produkt:
1. **Buchung aktivieren**
2. Pro Kurs eine Zeile: Anzeigename, Filter (Name enthält / Tag), Suchbegriff, optional Ort
3. Speichern. Die Vorschau zeigt, welche Amelia-Events gefunden werden. Unter "Alle kommenden Amelia-Events" stehen Namen und Tags zum Nachschlagen.

Produkte aus v1 übernehmen ihre alten Kurstitel automatisch als "Name enthält"-Filter, bis sie einmal gespeichert werden.

## Freie Plätze und Badges (seit v2.1)

Freie Plätze = Amelia-Kapazität - Amelia-Buchungen (approved/pending, Personen) - Kombi-Bestellungen (WooCommerce-Status processing/completed/on-hold, mit Menge).

Pro Termin ein oder zwei Badges, **nur mit echten Fakten**:
- `Ausgebucht`: 0 frei, Termin nicht wählbar (auch serverseitig geprüft)
- `Nur noch X Plätze frei`: ab 5 freien Plätzen oder weniger (Filter `bs_booking_low_seats`)
- `Startet in X Tagen` / `morgen` / `heute`: ab 14 Tagen vor Beginn (Filter `bs_booking_urgency_days`)
- sonst `Plätze frei`

Keine künstliche Verknappung: eine unwahre Knappheitsangabe wäre irreführende Werbung (UWG §5).
Events mit Ticket-Preisen (customPricing) oder ohne Kapazität gelten als "Kapazität unbekannt" und bekommen keine Platzangabe.
Die Produkt-Box zeigt pro Termin die Rechnung (z.B. "10 frei (15 Plätze, 3 Amelia, 2 Kombi)").

## Bewusste Grenzen

- **Kein Zurückschreiben an Amelia.** Kombi-Buchungen stehen nur in den WooCommerce-Bestellungen (Meta `_bs_amelia_event_ids`), nicht in Amelias Teilnehmerlisten. Amelia selbst zeigt deshalb zu viele freie Plätze an, die Kombi-Seite rechnet sie ab.

## Dateien

- `includes/amelia.php` Lesen der Amelia-Tabellen, UTC-Umrechnung, Tage aus Zeiträumen
- `includes/courses.php` Kurs-Konfiguration, Matching, Formatierung
- `includes/availability.php` Freie Plätze, Kombi-Bestellungen, Badges
- `includes/admin.php` Produkt-Metabox mit Vorschau
- `includes/frontend.php` Terminwahl auf der Produktseite, AJAX "In Warenkorb"
- `includes/cart.php` Anzeige in Warenkorb, Checkout und Bestellung
- `build/frontend.css`, `build/frontend.js` Frontend-Assets (handgeschrieben, kein Build-Schritt)
