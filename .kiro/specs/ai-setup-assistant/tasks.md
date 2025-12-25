# Implementation Tasks: AI Onboarding Assistant

## Task 1: Database Migration
- [x] Create `database/migration_onboarding_assistant.sql`
  - Create `onboarding_sessions` table
  - Create `setup_progress` table
  - Add indexes for performance

## Task 2: Core Classes
- [x] Create `modules/Onboarding/SetupStatusChecker.php`
  - Implement all check methods (LINE, Webhook, LIFF, Shop, etc.)
  - Implement `getCompletionPercentage()`
  - Implement `getNextRecommendedAction()`

- [x] Create `modules/Onboarding/SystemKnowledgeBase.php`
  - Define knowledge topics
  - Implement feature info retrieval
  - Implement navigation paths
  - Implement business type tips

- [x] Create `modules/Onboarding/OnboardingPromptBuilder.php`
  - Build system prompt with context
  - Build user prompt with knowledge
  - Extract intent from message

- [x] Create `modules/Onboarding/QuickActionExecutor.php`
  - Define available actions
  - Implement action execution
  - Implement validation

- [x] Create `modules/Onboarding/OnboardingAssistant.php`
  - Main chat interface
  - Integrate all components
  - Session management

## Task 3: API Endpoint
- [x] Create `api/onboarding-assistant.php`
  - Chat endpoint
  - Status endpoint
  - Checklist endpoint
  - Quick action endpoint
  - Health check endpoint

## Task 4: Frontend UI
- [x] Create `onboarding-assistant.php` (Admin page)
  - Chat interface
  - Checklist sidebar
  - Quick action buttons
  - Progress bar

## Task 5: Integration
- [x] Add onboarding button to header
- [ ] Add contextual help triggers
- [ ] Test full flow

## Task 6: Run Migration
- [x] Create `run_onboarding_migration.php`
- [ ] Execute migration

## Summary
AI Onboarding Assistant implementation completed with:
- Database migration for sessions and progress tracking
- Core classes: SetupStatusChecker, SystemKnowledgeBase, OnboardingPromptBuilder, QuickActionExecutor, OnboardingAssistant
- API endpoint with chat, status, checklist, and health check
- Frontend UI with chat interface, checklist sidebar, and quick actions
- Header integration with assistant button
