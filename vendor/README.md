# Mitgelieferte Fremdbibliothek

Damit die Karte **ohne jede fremde Domain** laufen kann, liegt Leaflet hier im
Repo: liegen `vendor/leaflet.js` und `vendor/leaflet.css` neben der `index.php`,
nimmt die Seite die statt der Fassung von unpkg.com.

## Leaflet 1.9.4

- Herkunft: offizielles Release-Archiv des Leaflet-Projekts
  <https://github.com/Leaflet/Leaflet/releases/download/v1.9.4/leaflet.zip>
- Build-Kennung laut Dateikopf: `1.9.4+v1.d15112c`
- Lizenz: BSD-2-Clause, © 2010–2023 Vladimir Agafonkin, © 2010–2011 CloudMade.
  Der Lizenz- und Copyright-Hinweis steht unverändert im Kopf von
  `leaflet.js` (`/* @preserve … */`). Volltext:
  <https://github.com/Leaflet/Leaflet/blob/v1.9.4/LICENSE>

### Prüfsummen (SHA-256, Base64 – wie bei Subresource Integrity)

```
leaflet.css  sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=
leaflet.js   sha256-hdRVtFIkFfa63EKg59F8kZ0QA0fWuJWL0Nxzj97NbVA=
```

Die CSS-Prüfsumme ist identisch mit dem auf unpkg veröffentlichten Build.
Die JS-Prüfsumme weicht davon ab, weil das GitHub-Release-Archiv mit einer
eigenen Build-Kennung erzeugt wurde – es ist dieselbe Version 1.9.4 aus
derselben Quelle, nur ein anderer Build-Lauf.

Nicht mitgeliefert: `leaflet-src.js`, die `.map`-Dateien und die
ESM-Variante – für den Betrieb nicht nötig.
