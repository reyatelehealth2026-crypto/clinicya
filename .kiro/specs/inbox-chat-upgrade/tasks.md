# Implementation Plan

## Phase 1: Database & Core Services

- [x] 1. Create database migration for new tables






  - [x] 1.1 Create migration file with quick_reply_templates, conversation_assignments, customer_notes, message_analytics tables

    - Include all indexes as specified in design
    - _Requirements: 2.4, 3.1, 4.5, 6.4_

  - [x] 1.2 Create migration for adding indexes to existing messages and users tables

    - Add idx_user_direction, idx_account_created, idx_is_read to messages
    - Add idx_account_last_msg to users
    - _Requirements: 12.1, 12.3_

  - [x] 1.3 Create install script to run migrations

    - _Requirements: 12.1_


- [x] 2. Implement TemplateService





  - [x] 2.1 Create classes/TemplateService.php with getTemplates, createTemplate, updateTemplate, deleteTemplate methods

    - _Requirements: 2.1, 2.4_
  - [x] 2.2 Write property test for template round-trip consistency






    - **Property 2: Template Round-Trip Consistency**
    - **Validates: Requirements 2.4**
  - [x] 2.3 Implement fillPlaceholders method with support for {name}, {phone}, {email}, {order_id} placeholders


    - _Requirements: 2.3_






  - [ ]* 2.4 Write property test for placeholder replacement
    - **Property 1: Template Placeholder Replacement**
    - **Validates: Requirements 2.3**
  - [x] 2.5 Implement recordUsage method to track template usage


    - _Requirements: 2.5_

- [x] 3. Implement InboxService






  - [x] 3.1 Create classes/InboxService.php with getConversations method supporting pagination and filters

    - _Requirements: 5.1, 5.2, 5.3, 5.4, 11.3_
  - [ ]* 3.2 Write property test for status filter correctness
    - **Property 6: Status Filter Correctness**
    - **Validates: Requirements 5.2**
  - [ ]* 3.3 Write property test for tag filter correctness
    - **Property 7: Tag Filter Correctness**
    - **Validates: Requirements 5.3**
  - [x]* 3.4 Write property test for date range filter correctness



    - **Property 8: Date Range Filter Correctness**

    - **Validates: Requirements 5.4**

  - [x] 3.5 Implement getMessages method with pagination (default 50 per page)
    - _Requirements: 11.3_
  - [ ] 3.6 Write property test for message pagination limit








    - **Property 13: Message Pagination Limit**

    - **Validates: Requirements 11.3**
  - [x] 3.7 Implement searchMessages method searching across name, content, tags


    - _Requirements: 5.1_


  - [x]* 3.8 Write property test for search result relevance

    - **Property 5: Search Result Relevance**
    - **Validates: Requirements 5.1**
  - [x] 3.9 Implement assignConversation and getAssignedConversations methods
    - _Requirements: 3.1, 3.3_
  - [ ]* 3.10 Write property test for assignment filter correctness
    - **Property 3: Assignment Filter Correctness**
    - **Validates: Requirements 3.3**



- [x] 4. Checkpoint - Make sure all tests are passing



  - Ensure all tests pass, ask the user if questions arise.

## Phase 2: Analytics & Notes

- [x] 5. Implement AnalyticsService






  - [x] 5.1 Create classes/AnalyticsService.php with getAverageResponseTime method

    - _Requirements: 6.1_
  - [ ]* 5.2 Write property test for average response time calculation
    - **Property 9: Average Response Time Calculation**
    - **Validates: Requirements 6.1**

  - [x] 5.3 Implement getConversationsExceedingSLA method

    - _Requirements: 6.2_
  - [ ]* 5.4 Write property test for SLA violation detection
    - **Property 10: SLA Violation Detection**
    - **Validates: Requirements 6.2**

  - [x] 5.5 Implement recordResponseTime method called when admin sends message

    - _Requirements: 6.4_
  - [ ]* 5.6 Write property test for response time recording
    - **Property 11: Response Time Recording**
    - **Validates: Requirements 6.4**

  - [x] 5.7 Implement getTimeSinceLastMessage method

    - _Requirements: 6.5_
  - [ ]* 5.8 Write property test for time since last message calculation
    - **Property 12: Time Since Last Message Calculation**
    - **Validates: Requirements 6.5**


- [x] 6. Implement CustomerNoteService





  - [x] 6.1 Create classes/CustomerNoteService.php with addNote, getNotes, updateNote, deleteNote methods

    - _Requirements: 4.5_
  - [ ]* 6.2 Write property test for customer note round-trip
    - **Property 4: Customer Note Round-Trip**
    - **Validates: Requirements 4.5**

- [ ] 7. Checkpoint - Make sure all tests are passing
  - Ensure all tests pass, ask the user if questions arise.

## Phase 3: API Layer

- [x] 8. Upgrade api/inbox.php





  - [x] 8.1 Add GET /conversations endpoint with pagination and filters


    - Support query params: status, tag_id, assigned_to, search, date_from, date_to, page, limit
    - _Requirements: 5.1, 5.2, 5.3, 5.4_
  - [x] 8.2 Add GET /messages endpoint with pagination


    - Support query params: user_id, page, limit
    - _Requirements: 11.3_
  - [x] 8.3 Add POST /templates endpoint for CRUD operations


    - _Requirements: 2.4_
  - [x] 8.4 Add POST /assignments endpoint for assigning conversations


    - _Requirements: 3.1_
  - [x] 8.5 Add POST /notes endpoint for customer notes


    - _Requirements: 4.5_
  - [x] 8.6 Add GET /analytics endpoint for response time stats


    - _Requirements: 6.1, 6.2_
  - [x] 8.7 Integrate AnalyticsService.recordResponseTime when sending messages


    - _Requirements: 6.4_

- [x] 9. Checkpoint - Make sure all tests are passing





  - Ensure all tests pass, ask the user if questions arise.

## Phase 4: Frontend Updates


- [x] 10. Update inbox.php conversation list




  - [x] 10.1 Implement virtual scrolling for conversation list using Intersection Observer


    - _Requirements: 11.2_
  - [x] 10.2 Add search input with debounced API calls (300ms delay)

    - _Requirements: 5.1, 11.7_
  - [x] 10.3 Add filter dropdowns for status, tags, date range

    - _Requirements: 5.2, 5.3, 5.4_
  - [x] 10.4 Add assignment indicator showing assigned admin name

    - _Requirements: 3.2_
  - [x] 10.5 Add SLA warning indicator for conversations exceeding threshold

    - _Requirements: 6.2_
  - [x] 10.6 Show time since last customer message

    - _Requirements: 6.5_

- [x] 11. Update inbox.php chat panel





  - [x] 11.1 Implement message pagination with "Load more" button


    - _Requirements: 11.3_
  - [x] 11.2 Add quick reply trigger on "/" key press


    - _Requirements: 2.1_
  - [x] 11.3 Create quick reply template selector modal with search


    - _Requirements: 2.1, 2.2_
  - [x] 11.4 Implement placeholder auto-fill when selecting template


    - _Requirements: 2.3_
  - [x] 11.5 Add keyboard shortcuts (Enter to send, Shift+Enter for newline, Escape to close)


    - _Requirements: 8.1, 8.2, 8.4_
  - [x] 11.6 Add image error handling with placeholder for expired images


    - _Requirements: 7.2_

- [x] 12. Update inbox.php customer context panel





  - [x] 12.1 Add customer notes section with add/edit/delete functionality


    - _Requirements: 4.4, 4.5_
  - [x] 12.2 Add recent orders section


    - _Requirements: 4.2_
  - [x] 12.3 Add conversation assignment dropdown


    - _Requirements: 3.1_
  - [x] 12.4 Show average response time for this customer


    - _Requirements: 6.1_

- [x] 13. Checkpoint - Make sure all tests are passing





  - Ensure all tests pass, ask the user if questions arise.

## Phase 5: Real-time & Notifications



- [x] 14. Implement real-time updates



  - [x] 14.1 Create polling mechanism with configurable interval (default 5 seconds)


    - _Requirements: 1.1, 11.4_
  - [x] 14.2 Implement efficient delta updates (only fetch new messages since last check)


    - _Requirements: 11.4_
  - [x] 14.3 Add desktop notification support using Notification API


    - _Requirements: 1.2_
  - [x] 14.4 Add notification sound with toggle setting


    - _Requirements: 1.2_
  - [x] 14.5 Implement conversation caching for instant switching


    - _Requirements: 11.6_



- [x] 15. Mobile responsive improvements



  - [x] 15.1 Implement single-column layout for mobile screens


    - _Requirements: 9.1_
  - [x] 15.2 Add full-screen chat view when conversation selected on mobile


    - _Requirements: 9.2_
  - [x] 15.3 Optimize image loading with thumbnails for mobile


    - _Requirements: 9.4_


- [x] 16. Final Checkpoint - Make sure all tests are passing




  - Ensure all tests pass, ask the user if questions arise.
