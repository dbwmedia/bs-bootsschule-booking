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
- `Nur noch wenige Plätze`: ab 14 Tagen vor Beginn, unabhängig von der echten Zahl (Kundenwunsch)
- `Startet in X Tagen` / `morgen` / `heute`: ab 14 Tagen vor Beginn (Filter `bs_booking_urgency_days`)
- sonst `Plätze frei`

**Achtung:** "Nur noch wenige Plätze" ohne echte Knappheit ist rechtlich angreifbar (UWG §5, irreführende Knappheitsangabe). Auf ausdrücklichen Wunsch des Kunden eingebaut, Risiko ist ihm bekannt (Hinweis vom 17.09.2026). Abschalten per `add_filter('bs_booking_scarcity_hint', '__return_false');`
Events mit Ticket-Preisen (customPricing) oder ohne Kapazität gelten als "Kapazität unbekannt" und bekommen keine Platzangabe.
Die Produkt-Box zeigt pro Termin die Rechnung (z.B. "10 frei (15 Plätze, 3 Amelia, 2 Kombi)").

## Buchungen in Amelia (seit v2.5)

Kombi-Bestellungen werden als echte Amelia-Buchungen angelegt, eine pro Kurs (`includes/amelia-sync.php`):

| Bestellstatus | Aktion |
|---|---|
| on-hold / processing / completed | fehlende Buchungen anlegen (Status nach Amelias WooCommerce-Regeln) |
| cancelled / failed / refunded | angelegte Buchungen stornieren |

- Nutzt denselben internen Aufruf wie Amelias eigene WooCommerce-Integration (`EventReservationService::processRequest`, Gateway `wc`). Geprüft gegen **Amelia 9.8**. Nach Amelia-Updates einmal testen.
- Fehler blockieren nie die Bestellung, sie landen als Bestellnotiz.
- Buchungs-IDs stehen im Item-Meta `_bs_amelia_bookings`, jeder Lauf ist dadurch idempotent.
- Der Kombi-Preis wird anteilig nach Amelia-Listenpreisen auf die Kurse verteilt (Zahlungsbetrag in Amelia).
- **Keine Amelia-Mails**: Buchungen werden als "actions completed" markiert, sonst würde Amelias Cron nach 5 Minuten nachsenden. Einschalten: `add_filter('bs_booking_amelia_notifications', '__return_true');`
- Bereits in Amelia gebuchte Kombi-Kurse werden bei den freien Plätzen nicht mehr zusätzlich abgezogen.

## Dateien

- `includes/amelia.php` Lesen der Amelia-Tabellen, UTC-Umrechnung, Tage aus Zeiträumen
- `includes/courses.php` Kurs-Konfiguration, Matching, Formatierung
- `includes/availability.php` Freie Plätze, Kombi-Bestellungen, Badges
- `includes/admin.php` Produkt-Metabox mit Vorschau
- `includes/frontend.php` Terminwahl auf der Produktseite, AJAX "In Warenkorb"
- `includes/cart.php` Anzeige in Warenkorb, Checkout und Bestellung
- `includes/amelia-sync.php` Kombi-Bestellungen als Amelia-Buchungen anlegen und stornieren
- `includes/upsell.php` Kombi-Upsell-Box auf den Einzelprodukten
- `build/frontend.css`, `build/frontend.js` Frontend-Assets (handgeschrieben, kein Build-Schritt)

## Upsell-Box (seit v2.6)

Auf Einzelprodukten (z.B. SBF Binnen, SBF See) erscheint über der Amelia-Terminliste eine Box, die das Kombi-Produkt bewirbt (`includes/upsell.php`).

- Einstellung im **Kombi-Produkt**: "Upsell-Box auf Einzelprodukten", dort alle Einzelkurse wählen, aus denen die Kombi besteht.
- Zahlen sind echt: Summe der Einzelpreise durchgestrichen, Kombi-Preis, Ersparnis in € und % (abgerundet), nächster buchbarer Start pro Kurs.
- Keine Box, wenn es keine Ersparnis gibt, das Kombi-Produkt nicht kaufbar ist oder ein Kurs keinen freien Termin mehr hat.

## Autoren

- Ab v2.0: Neuentwicklung mit Amelia-Anbindung durch Dennis Buchwald (dbw media).
- v1.x (eigene Terminverwaltung, Kombi-Produkt-Grundidee): Julio Litzenberg.
