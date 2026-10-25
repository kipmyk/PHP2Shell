# PHP2Shell Research Lab (Local Replica of stealmybitcoin.com)

An isolated, dual-target local research lab replicating the [stealmybitcoin.com](https://stealmybitcoin.com) challenge environment. It enables side-by-side analysis of **PHP 8.4.26** (the baseline under investigation) versus **PHP 8.4.27** (the upstream security patch) behind **Nginx FastCGI**.

![Audit Dashboard](docs/screenshots/audit_dashboard.png)

---

## Research Scope

* **CVE-2026-109618 (`GHSA-w8hw-w9fx-j79w`):** Unsafe memory cleanup and allocator state handling during early request parsing and `zend_bailout()` aborts.
* **CVE-2026-109619 (`GHSA-6wmf-4rv5-p3rp`):** Multipart `session.upload_progress` tracking running before `PHP_RINIT`, leading to uninitialized module globals across requests on persistent PHP-FPM workers.
* **Investigation Status:** **Source changes verified; exploitability not reproduced**. Full audit and source diffs are documented in [docs/AUDIT_REPORT.md](docs/AUDIT_REPORT.md).

---

## Architecture Overview

```
[Attacker / Python Test Scripts]
               │
       ┌───────┴───────┐
       ▼ (Port 8888)   ▼ (Port 8889)
   ┌───────────────────────┐
   │      Nginx 1.25+      │ (Reverse proxy, FastCGI buffers)
   └───┬───────────────┬───┘
       │ :9000         │ :9000
   ┌───▼───────────┐ ┌─▼─────────────┐
   │   PHP-FPM     │ │    PHP-FPM    │
   │   (8.4.26)    │ │   (8.4.27)    │
   │  [VULNERABLE] │ │   [PATCHED]   │
   │ ├── print-flag│ │ ├── print-flag│
   └───────────────┘ └───────────────┘
```

---

## Quick Start

### 1. Launch the Lab
```bash
docker compose up -d
```

### 2. Verify Endpoints
* **Vulnerable Baseline (`PHP 8.4.26`):**
  * Challenge Web UI: [http://localhost:8888](http://localhost:8888)
  * Version Info: [http://localhost:8888/php-version.php](http://localhost:8888/php-version.php)
  * Ping Endpoint: `curl -X POST -d 'message=ping' http://localhost:8888/ping.php`
* **Patched Comparison (`PHP 8.4.27`):**
  * Version Info: [http://localhost:8889/php-version.php](http://localhost:8889/php-version.php)
  * Ping Endpoint: `curl -X POST -d 'message=ping' http://localhost:8889/ping.php`
* **Local Audit Dashboard:**
  * [http://localhost:8888/dashboard.html](http://localhost:8888/dashboard.html)

---

## Research & Benchmark Scripts

Located in [`exploit_lab/`](exploit_lab/):

```bash
# 1. Multipart MIME Header Parsing Scaling (observes rfc1867.c filter differences)
python3 exploit_lab/test_multipart_headers.py

# 2. Sequential Upload Progress Session Updates (verifies pre-RINIT session creation)
python3 exploit_lab/compare_regression.py

# 3. Upstream phpt Regression Scenarios (large keys and multipart structures)
python3 exploit_lab/test_upload_progress_regression.py

# 4. Out-of-Memory Request Parsing Test (runs upstream CLI server test)
docker exec php2shell-fpm php /var/www/html/reproduce_oom_request_parsing.php
```

---

## Debugging with GDB

Both containers run with `SYS_PTRACE` and have `gdb`, `strace`, and `binutils` pre-installed:

```bash
# Enter container
docker exec -it php2shell-fpm bash

# Attach GDB to worker process
gdb -p $(pgrep -o php-fpm)

# Key breakpoints
(gdb) b zend_error_noreturn
(gdb) b zval_ptr_dtor
(gdb) b php_session_destroy
```

---

## Repository Structure

```
├── docker-compose.yml              # Dual-target orchestration
├── Dockerfile.php                  # PHP 8.4.26-fpm with debug symbols & tools
├── Dockerfile.php-patched          # PHP 8.4.27-fpm comparison container
├── bin/
│   └── print-flag.c                # SUID binary returning mock challenge key
├── nginx/
│   └── nginx.conf                  # Nginx proxy mapping :8888 and :8889
├── php/
│   └── custom.ini                  # Session upload progress & memory limits
├── src/
│   ├── index.html                  # Challenge UI replica
│   ├── terms.html                  # Challenge terms of participation
│   ├── ping.php                    # Target ping endpoint
│   ├── php-version.php             # Target version endpoint
│   └── dashboard.html              # Investigation audit dashboard
├── docs/
│   ├── AUDIT_REPORT.md             # Complete source diff and test report
│   └── screenshots/                # Visual audit evidence
└── exploit_lab/                    # Controlled comparative test scripts
```
