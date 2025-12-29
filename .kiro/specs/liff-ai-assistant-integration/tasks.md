# Implementation Plan

## Overview
เชื่อมต่อ LIFF AI Assistant (`/liff/#/ai-assistant`) เข้ากับระบบ AI ที่มีอยู่แล้ว (MIMSPharmacistAI, TriageEngine, RedFlagDetector, pharmacist-dashboard, triage-analytics)

---

- [x] 1. Update API Layer for Session Continuity
  - [x] 1.1 Enhance pharmacy-ai.php to support session-based conversation
    - Add session_id parameter handling
    - Load conversation history (last 10 messages) for context
    - Pass history to PharmacyAIAdapter
    - _Requirements: 6.5, 9.5_
  - [x] 1.2 Add session state persistence in API
    - Save triage state with each message
    - Return current state in response
    - Prevent greeting when session is active
    - _Requirements: 9.1, 9.5_
  - [x] 1.3 Write property test for session continuity
    - **Property 7: Session Continuity - No Greeting During Active Session**
    - **Validates: Requirements 9.1**

- [x] 2. Enhance TriageEngine for Conversation Continuity
  - [x] 2.1 Update TriageEngine to interpret numeric responses
    - Detect numeric input (1-10) in severity state
    - Parse and save severity value
    - Transition to next state
    - _Requirements: 9.3_
  - [x] 2.2 Update TriageEngine to interpret duration responses
    - Detect duration patterns (วัน, สัปดาห์, เดือน)
    - Parse and save duration value
    - Transition to next state
    - _Requirements: 9.4_
  - [x] 2.3 Update TriageEngine to prevent greeting during active session
    - Check session status before greeting
    - Continue from current state if session is active
    - _Requirements: 9.1, 9.5_
  - [x] 2.4 Write property test for numeric response interpretation
    - **Property 8: Numeric Response Interpretation**
    - **Validates: Requirements 9.3**
  - [x] 2.5 Write property test for duration response interpretation



















    - **Property 9: Duration Response Interpretation**
    - **Validates: Requirements 9.4**





  - [x] 2.6 Write property test for state persistence







    - **Property 10: State Persistence**
    - **Validates: Requirements 9.5**

- [x] 3. Checkpoint - Ensure all tests pass





  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Update Frontend ai-chat.js Component
  - [x] 4.1 Add session management to ai-chat.js
    - Generate/load session_id
    - Track current triage state
    - Send state with each API call
    - _Requirements: 9.1, 9.5_
  - [x] 4.2 Update processMessage to include conversation context
    - Send last messages as context
    - Handle state transitions from API response
    - Update UI based on state
    - _Requirements: 6.5, 9.2_
  - [x] 4.3 Enhance emergency alert display
    - Show prominent alert for critical red flags
    - Display emergency contact numbers (1669, 1323, 1367)
    - Add call button for emergency numbers
    - _Requirements: 3.2, 3.3_
  - [x] 4.4 Write property test for emergency alert display






    - **Property 3: Emergency Alert Display**
    - **Validates: Requirements 3.2**

- [x] 5. Enhance Conversation History Management
  - [x] 5.1 Update conversation history API endpoints
    - Add session_id to history records
    - Implement get_history with user isolation
    - Implement clear_history for specific user only
    - _Requirements: 6.1, 6.2, 6.3_
  - [x] 5.2 Update ai-chat.js to load and display history
    - Load history on page init
    - Display messages in chronological order
    - Show timestamps for messages
    - _Requirements: 6.2, 6.4_
  - [x] 5.3 Write property test for conversation history persistence














    - **Property 4: Conversation History Persistence**
    - **Validates: Requirements 6.1, 6.2**

  - [x] 5.4 Write property test for user-specific history isolation







    - **Property 5: User-Specific History Isolation**
    - **Validates: Requirements 6.3**

- [x] 6. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Integrate RedFlagDetector with LIFF
  - [x] 7.1 Ensure RedFlagDetector is called for every message
    - Call detect() before AI processing
    - Check isCritical() for emergency handling
    - Log red flags to database
    - _Requirements: 3.1, 3.4_
  - [x] 7.2 Add emergency logging to database
    - Create emergency_alerts table if needed
    - Log critical and warning flags
    - Include user context and timestamp
    - _Requirements: 3.4_
  - [x] 7.3 Write property test for red flag detection accuracy
    - **Property 2: Red Flag Detection Accuracy**
    - **Validates: Requirements 3.1**

- [x] 8. Enhance Drug Interaction and Allergy Checking
  - [x] 8.1 Integrate drug interaction check in recommendations
    - Load user's current medications from health profile
    - Check interactions with recommended drugs
    - Filter out allergic medications
    - _Requirements: 7.1, 7.3_
  - [x] 8.2 Display drug interaction warnings in UI
    - Show warning message for interactions
    - Highlight allergic medications
    - Include interaction details
    - _Requirements: 7.2, 7.4_
  - [x] 8.3 Write property test for drug allergy filtering
    - **Property 11: Drug Allergy Filtering**
    - **Validates: Requirements 7.3**
  - [x] 8.4 Write property test for drug interaction warning
    - **Property 12: Drug Interaction Warning**
    - **Validates: Requirements 7.2, 7.4**



- [x] 9. Checkpoint - Ensure all tests pass







  - Ensure all tests pass, ask the user if questions arise.

- [x] 10. Connect to Pharmacist Dashboard
  - [x] 10.1 Ensure pharmacist notifications are created on escalation
    - Create notification when user requests pharmacist
    - Create notification when severity is high/critical
    - Include triage data in notification
    - _Requirements: 4.1, 4.2_
  - [x] 10.2 Update pharmacist-dashboard to display AI triage notifications
    - Show notifications with user info
    - Display triage symptoms and severity
    - Add action buttons (view, handle, video call)
    - _Requirements: 4.3_
  - [x] 10.3 Write property test for pharmacist notification on high severity
    - **Property 13: Pharmacist Notification on High Severity**
    - **Validates: Requirements 4.2**

- [x] 11. Connect to Triage Analytics
  - [x] 11.1 Ensure triage sessions are properly updated on completion
    - Update status to 'completed' when session ends
    - Set completed_at timestamp
    - Calculate completion time
    - _Requirements: 5.1_
  - [x] 11.2 Verify triage-analytics displays correct statistics
    - Total sessions count
    - Completion rate calculation
    - Urgent cases count
    - Top symptoms aggregation
    - _Requirements: 5.2, 5.3_
  - [x] 11.3 Write property test for triage session completion update
    - **Property 14: Triage Session Completion Update**
    - **Validates: Requirements 5.1**


- [x] 12. Checkpoint - Ensure all tests pass




  - Ensure all tests pass, ask the user if questions arise.

- [x] 13. Add Quick Symptom Selection
  - [x] 13.1 Ensure quick symptom buttons work with triage flow
    - Send symptom text to API on click
    - Start triage session with symptom
    - Hide quick symptom section after selection
    - _Requirements: 8.1, 8.2, 8.3_
  - [x] 13.2 Verify follow-up questions based on TriageEngine state
    - Display duration question after symptom
    - Display severity question after duration
    - Continue triage flow
    - _Requirements: 8.4_

- [x] 14. Ensure Role Consistency





  - [x] 14.1 Update system prompt to maintain pharmacist persona
    - Include MIMS knowledge base context
    - Enforce triage protocol in prompt
    - Add redirection for off-topic questions
    - _Requirements: 10.1, 10.2, 10.4_
  - [x] 14.2 Verify system prompt is included in every API call
    - Check PharmacyAIAdapter includes system prompt
    - Verify MIMSPharmacistAI uses MIMS context
    - _Requirements: 10.5_


  - [x]* 14.3 Write property test for triage protocol sequence




    - **Property 15: Triage Protocol Sequence**
    - **Validates: Requirements 10.3**

- [x] 15. Final Checkpoint - Ensure all tests pass








  - Ensure all tests pass, ask the user if questions arise.
