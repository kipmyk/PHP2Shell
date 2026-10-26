# X Post Draft — PHP Security Research Accountability

**Author:** @itsmikekipruto  
**Date:** October 26, 2026  
**Platform:** X / Twitter  

---

My PHP security investigation taught me an important lesson: source analysis is not exploit reproduction.

I compared affected and patched PHP versions (8.4.26 vs 8.4.27) and tested multipart and session upload-progress behavior, but did not reproduce the reported memory corruption or RCE in our Debian FPM lab.

The published exploit chain was calibrated for an Alpine CLI-server multi-worker setup. SAPI, worker recycling, and allocator state matter.

Next: upstream regression tests, environment comparison, and reproducible evidence.

Full lab & audit: https://github.com/kipmyk/PHP2Shell
