# Task: Implement Global Rate Limiting for Multi-Worker Laravel Import

## Your Mission

You are tasked with building a **global rate limiting system** for a Laravel import command that uses multiple concurrent queue workers. The current implementation has a critical flaw: each worker tracks sleep time independently, resulting in impossible metrics (e.g., 20 minutes of sleep time in 1 minute of elapsed time).

## Primary Instruction Document

**Read this file FIRST and use it as your primary reference:**
```
/Users/danielcoulbourne/src/rate-test/GLOBAL_RATE_LIMIT_SPEC.md
```

This document contains:
- Complete project context and goals
- The exact problem you're solving with examples
- Detailed implementation approach with code samples
- Step-by-step instructions
- Testing strategy with success criteria
- Recursive testing loop to follow

## Your Approach

1. **Start by reading the spec document thoroughly** - It contains everything you need
2. **Research Saloon first** - As instructed in the spec, examine Saloon's extension points before writing code
3. **Build incrementally** - Follow the implementation steps in order
4. **Test frequently** - Use the recursive testing loop after each step
5. **Document your decisions** - Add comments explaining your Saloon integration approach

## Success Criteria

You will know you're done when:
- ✅ `total_sleep_seconds` is always less than `elapsed_time`
- ✅ Active time (`elapsed - sleep`) is always positive
- ✅ A full 20,000 item import completes successfully
- ✅ Filament dashboard at http://rate-test.test/admin shows accurate metrics
- ✅ Code is isolated in `app/Api/RateLimit/` directory (package-ready)

## Key Files

- **Spec**: `/Users/danielcoulbourne/src/rate-test/GLOBAL_RATE_LIMIT_SPEC.md` ← START HERE
- **Current connector**: `/Users/danielcoulbourne/src/rate-test/app/Api/RateTestConnector.php`
- **Import job**: `/Users/danielcoulbourne/src/rate-test/app/Jobs/ImportItemDetailsJob.php`
- **Import model**: `/Users/danielcoulbourne/src/rate-test/app/Models/Import.php`

## Important Context

- You have 2 Horizon workers running concurrently
- Redis is available and configured
- The Filament dashboard auto-refreshes every 5 seconds
- You can test with `php artisan import:items --fresh`
- Small tests (~30s, ~100 items) are faster for iteration

## Your First Steps

1. Read `GLOBAL_RATE_LIMIT_SPEC.md` completely
2. Research Saloon's extension points (Phase 0 in the spec)
3. Create the interface and store classes (Steps 1-3)
4. Create the trait with proper Saloon integration (Step 4)
5. Update the connector (Step 5)
6. Test with small import (Phase 2)
7. Iterate until metrics are correct
8. Run full 20k test (Phase 4)

## Communication Style

- Be concise - show me what you're doing, not endless explanations
- Use the TodoWrite tool to track your progress through the implementation steps
- When testing, show me the actual metrics (sleep vs elapsed)
- If you get stuck, re-read the spec and/or re-research Saloon

## Ready?

Begin by reading the spec document and confirming you understand the problem and approach.
