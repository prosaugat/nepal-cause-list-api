# API examples (curl)

Assuming the API is running on `http://localhost:8080`.

```bash
# API info
curl http://localhost:8080/

# Supported courts and whether each needs a court id
curl http://localhost:8080/v1/courts

# Today's date in Bikram Sambat
curl http://localhost:8080/v1/today

# Directories for the courts that need an id
curl http://localhost:8080/v1/highcourt/courts
curl http://localhost:8080/v1/districtcourt/courts

# Supreme Court — daily / weekly / supplementary (no court id)
curl "http://localhost:8080/v1/supremecourt/cause-lists/daily?date=2083-06-12"
curl "http://localhost:8080/v1/supremecourt/cause-lists/weekly?date=2083-06-12"
curl "http://localhost:8080/v1/supremecourt/cause-lists/supplementary?date=2083-06-12"

# Special Court and Consumer Court (no court id)
curl "http://localhost:8080/v1/specialcourt/cause-lists/daily?date=2083-06-12"
curl "http://localhost:8080/v1/consumercourt/cause-lists/weekly?date=2083-06-12"

# High Court and District Court (need ?court_id=)
curl "http://localhost:8080/v1/highcourt/cause-lists/daily?date=2083-06-12&court_id=1"
curl "http://localhost:8080/v1/districtcourt/cause-lists/daily?date=2083-06-12&court_id=1"
```
