# Parish MIS API High-Level User Stories

This document translates the currently implemented API into high-level agile user stories grouped by functional modules.

It is intentionally business-facing:
- high-level stories only
- grouped by modules
- focused on user goals and expected outcomes
- suitable for backlog grooming, planning, and stakeholder discussion

It is based on the implemented `v1` API structure in [routes/api.php](routes/api.php).

## Epic: Platform Administration

### Module: Admin Authentication

**User Story 1**
As a platform admin, I want to log in securely so that I can access the admin portal and manage all workspaces.

**User Story 2**
As a platform admin, I want to log out of the platform so that my session is closed securely.

**User Story 3**
As a platform admin, I want to view my current authenticated profile so that I can confirm I am operating with the correct admin identity.

### Module: Platform Overview

**User Story 4**
As a platform admin, I want to see a platform overview dashboard so that I can understand the current state of workspaces, properties, subscriptions, and billing activity.

### Module: Admin Analytics

**User Story 5**
As a platform admin, I want to view revenue trends over time so that I can monitor business growth and collections performance.

**User Story 6**
As a platform admin, I want to view subscription status trends so that I can monitor active, expired, and unsubscribed property coverage over time.

**User Story 7**
As a platform admin, I want to view property growth trends so that I can track onboarding and business expansion.

**User Story 8**
As a platform admin, I want to view the current subscription status split so that I can quickly understand the health mix of subscribed versus non-subscribed properties.

**User Story 9**
As a platform admin, I want to view the top billing rules used so that I can identify which billing ranges are driving platform revenue.

### Module: Billing Profiles and Billing Rules

**User Story 10**
As a platform admin, I want to list billing profiles so that I can review the pricing structures available on the platform.

**User Story 11**
As a platform admin, I want to create billing profiles so that I can introduce new pricing models for workspaces.

**User Story 12**
As a platform admin, I want to view a billing profile and its rules so that I can inspect how charges are applied across property ranges.

**User Story 13**
As a platform admin, I want to update a billing profile so that I can adjust pricing configuration without recreating it.

**User Story 14**
As a platform admin, I want to add rules to a billing profile so that workspace billing can adapt to different usage ranges.

**User Story 15**
As a platform admin, I want to update billing rules so that pricing changes can be applied to the correct range definitions.

**User Story 16**
As a platform admin, I want to list billing rules across profiles so that I can review pricing coverage at a platform level.

### Module: Workspace Management

**User Story 17**
As a platform admin, I want to list all workspaces so that I can search, monitor, and manage customers across the platform.

**User Story 18**
As a platform admin, I want to create a new workspace for an owner so that a new customer environment can be provisioned.

**User Story 19**
As a platform admin, I want to view workspace details so that I can inspect workspace metadata, status, and provisioning information.

**User Story 20**
As a platform admin, I want to retry failed workspace provisioning so that a workspace can recover from setup problems without manual database intervention.

**User Story 21**
As a platform admin, I want to suspend or reactivate a workspace so that platform access can be controlled according to operational or business needs.

### Module: Workspace Subscription Management

**User Story 22**
As a platform admin, I want to view a workspace subscription summary so that I can understand the workspace billing state and access state.

**User Story 23**
As a platform admin, I want to view subscription property breakdown for a workspace so that I can see how billing is derived at property level.

**User Story 24**
As a platform admin, I want to preview a billing profile change for a workspace so that I can understand the impact before applying it.

**User Story 25**
As a platform admin, I want to assign or schedule a new billing profile for a workspace so that workspace pricing can be updated safely.

**User Story 26**
As a platform admin, I want to update workspace subscription status so that I can control lifecycle states such as active, trialing, or canceled.

**User Story 27**
As a platform admin, I want to inspect a workspace access state so that I can know whether billing and workspace status rules currently allow operations.

### Module: Workspace Inspection

**User Story 28**
As a platform admin, I want to inspect staff within a selected workspace so that I can monitor workspace membership, role assignment, and staff activity.

**User Story 29**
As a platform admin, I want to view a compact workspace staff summary so that I can quickly assess staffing status without opening full staff lists.

**User Story 30**
As a platform admin, I want to view a workspace operational summary so that I can understand how that workspace is using the system right now.

**User Story 31**
As a platform admin, I want to view workspace property location summaries so that I can understand geographic distribution inside a selected workspace.

**User Story 32**
As a platform admin, I want to drill into workspace property location breakdowns so that I can analyze distribution by country, region, district, or ward.

**User Story 33**
As a platform admin, I want to inspect workspace properties with operational rollups so that I can review property usage and current state in one place.

**User Story 34**
As a platform admin, I want to view contract summaries for a selected workspace so that I can monitor occupancy and contract lifecycle health.

### Module: Property Subscription Operations

**User Story 35**
As a platform admin, I want to list property subscriptions for a selected workspace so that I can review subscription states at property level.

**User Story 36**
As a platform admin, I want to view one property subscription in detail so that I can inspect its billing rule, current period, and recent payment context.

**User Story 37**
As a platform admin, I want to view payment history for a property subscription so that I can audit its billing coverage over time.

**User Story 38**
As a platform admin, I want to preview a property subscription payment before recording it so that I can validate the expected billing coverage and amount.

**User Story 39**
As a platform admin, I want to record a property subscription payment so that a property can become or remain actively subscribed.

### Module: Usage Adjustments

**User Story 40**
As a platform admin, I want to preview workspace usage adjustments so that I can understand pending billing corrections before applying them.

**User Story 41**
As a platform admin, I want to list usage adjustments so that I can monitor overages, corrections, and billing-related exceptions.

**User Story 42**
As a platform admin, I want to apply a usage adjustment so that billing can reflect approved operational changes.

**User Story 43**
As a platform admin, I want to waive a usage adjustment so that exceptional cases can be resolved without charging the customer.

### Module: Admin Reports

**User Story 44**
As a platform admin, I want to view payment collection summaries so that I can track collected billing amounts across the platform.

**User Story 45**
As a platform admin, I want to view workspace subscription reports so that I can compare billing and subscription performance across workspaces.

**User Story 46**
As a platform admin, I want to view expired and unsubscribed property reports so that I can identify follow-up and revenue recovery opportunities.

### Module: Automation Tasks

**User Story 47**
As a platform admin, I want to list configured automation tasks so that I can monitor recurring operational jobs.

**User Story 48**
As a platform admin, I want to inspect automation task runs so that I can review execution history and troubleshoot failures.

**User Story 49**
As a platform admin, I want to update automation task settings so that scheduling and enablement can be adjusted without code changes.

**User Story 50**
As a platform admin, I want to trigger an automation task immediately so that urgent operational processes can run on demand.

## Epic: Workspace User Experience

### Module: App Authentication and Session

**User Story 51**
As a workspace user, I want to register an account and create my primary workspace so that I can start using the platform.

**User Story 52**
As a workspace user, I want to log in with my credentials so that I can access my workspace securely.

**User Story 53**
As a workspace user, I want to verify login with OTP so that the platform can apply an extra layer of security.

**User Story 54**
As a workspace user, I want to recover my password so that I can regain access when I forget my credentials.

**User Story 55**
As a workspace user, I want to reset my password securely so that I can restore access after verification.

**User Story 56**
As a workspace user, I want to change my password while logged in so that I can keep my account secure.

**User Story 57**
As a workspace user, I want to refresh my session token so that I can stay signed in without re-authenticating too often.

**User Story 58**
As a workspace user, I want to log out so that my session is terminated securely.

**User Story 59**
As a workspace user, I want to view my authenticated session details, active workspace, and workspace profile so that I can confirm where I am operating.

### Module: Workspace Subscription Visibility

**User Story 60**
As a workspace user, I want to view my workspace subscription summary so that I can understand billing state and access limitations.

**User Story 61**
As a workspace user, I want to preview a billing profile change so that I can understand pricing impact before requesting or applying it.

**User Story 62**
As a workspace user, I want to see property-level subscription billing breakdown so that I can understand how workspace subscription costs are formed.

### Module: Access Control

**User Story 63**
As a workspace admin, I want to view available permissions so that I can understand what capabilities can be granted.

**User Story 64**
As a workspace admin, I want to create permissions so that access control can evolve with business needs.

**User Story 65**
As a workspace admin, I want to view roles so that I can manage how permissions are grouped.

**User Story 66**
As a workspace admin, I want to create roles so that responsibilities can be assigned consistently.

**User Story 67**
As a workspace admin, I want to inspect a role so that I can review its permission set.

**User Story 68**
As a workspace admin, I want to update role permissions so that access rules remain aligned with operational responsibilities.

**User Story 69**
As a workspace admin, I want to delete roles when they are no longer needed so that access control remains clean and manageable.

**User Story 70**
As a workspace admin, I want to assign direct permissions to a staff user so that exceptional access can be granted without changing role structure.

### Module: Locations

**User Story 71**
As a workspace user, I want to retrieve countries, regions, districts, and wards so that I can capture accurate location information in workspace records.

### Module: Property and Inventory Setup

**User Story 72**
As a workspace admin, I want to manage property types so that properties can be categorized consistently.

**User Story 73**
As a workspace admin, I want to create and manage properties so that the workspace inventory structure reflects the real estate portfolio.

**User Story 74**
As a workspace admin, I want to create and manage property floors so that buildings can be represented accurately.

**User Story 75**
As a workspace admin, I want to create and manage units so that rentable spaces can be tracked individually.

### Module: Maintenance Management

**User Story 76**
As a workspace user, I want to manage maintenance jobs so that maintenance work can be planned, tracked, and completed.

**User Story 77**
As a workspace user, I want to manage maintenance expenses so that maintenance-related costs are recorded and reportable.

**User Story 78**
As a workspace manager, I want maintenance summary reports so that I can monitor workload and maintenance spending patterns.

### Module: Customer Management

**User Story 79**
As a workspace user, I want to manage customers so that people or organizations connected to units and contracts can be recorded properly.

### Module: Contract Management

**User Story 80**
As a workspace user, I want to manage customer contracts so that occupancy, rental periods, and leasing agreements are tracked digitally.

**User Story 81**
As a workspace manager, I want contract summary reports so that I can monitor occupancy and contract lifecycle trends.

**User Story 82**
As a workspace manager, I want contract breakdown by property so that I can compare contract activity across the portfolio.

**User Story 83**
As a workspace manager, I want to identify expiring contracts so that renewals and tenant follow-up can happen on time.

### Module: Staff and Assignment Management

**User Story 84**
As a workspace admin, I want to manage workspace staff users so that the right people can access the workspace system.

**User Story 85**
As a workspace admin, I want to assign staff to specific properties so that responsibilities can be scoped to the correct locations.

### Module: Workspace Dashboard and Reporting

**User Story 86**
As a workspace user, I want a dashboard overview so that I can quickly understand key operational metrics in my workspace.

## Notes

- These stories are intentionally high-level and module-oriented.
- They describe the implemented API capability, not future desired scope.
- Detailed acceptance criteria can be produced next if needed.
- Detailed endpoint-by-endpoint technical specifications can also be produced separately.
