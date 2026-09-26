# Work Breakdown Structure (WBS) dan Task Plan

## 1. WBS Level 1
- 1.0 Project Setup
- 2.0 Core Platform Architecture
- 3.0 Authentication & User Management
- 4.0 Content and Timeline
- 5.0 Federation Layer
- 6.0 Payment Layer
- 7.0 External Content Integration
- 8.0 Toko Online Pribadi & Federated Commerce
- 9.0 Admin Dashboard
- 10.0 Testing, Security, and Deployment

## 2. WBS Detail

### 1.0 Project Setup
1.1 Setup workspace dan repository
1.2 Setup environment PHP + MySQL
1.3 Setup autoload dan base configuration
1.4 Setup logging dan storage folder

### 2.0 Core Platform Architecture
2.1 Define modular monolith structure
2.2 Configure service layer and repository pattern
2.3 Configure adapter, strategy, and factory patterns
2.4 Prepare base database schema

### 3.0 Authentication & User Management
3.1 User registration and login
3.2 Profile engine
3.3 Session-based authentication
3.4 API token management
3.5 Admin role and permissions

### 4.0 Content and Timeline
4.1 Post CRUD
4.2 Comment, like, bookmark
4.3 Timeline query aggregation
4.4 Local post presentation
4.5 Source attribution and visibility rules

### 5.0 Federation Layer
5.1 Federation node registration
5.2 Capability discovery
5.3 Remote actor model
5.4 Activity queue and relay
5.5 Federated content handling

### 6.0 Payment Layer
6.1 Create PaymentGatewayInterface
6.2 Build PaymentGatewayFactory
6.3 Implement DummyPaymentGateway
6.4 Add payment tables and config
6.5 Build payment service and webhook pipeline
6.6 Add refund and duplicate-event protection
6.7 Add admin settings for gateway configuration

### 7.0 External Content Integration
7.1 Create ExternalContentProviderInterface
7.2 Build ExternalConnectorFactory
7.3 Implement RSS connector
7.4 Implement Atom connector
7.5 Implement custom API connector
7.6 Build integration queue and scheduler
7.7 Build normalized content model
7.8 Add external post attribution display
7.9 Add rate limit and retry handling

### 8.0 Toko Online Pribadi & Federated Commerce
8.1 Product data model
8.2 Product listing and query
8.3 Local checkout flow
8.4 External product labeling
8.5 Federated product order request workflow

### 9.0 Admin Dashboard
9.1 Settings pages
9.2 Payment configuration UI
9.3 External connection settings UI
9.4 Cron and queue monitoring
9.5 Security settings

### 10.0 Testing, Security, and Deployment
10.1 Unit tests for payment service
10.2 Integration tests for connectors
10.3 Webhook idempotency tests
10.4 Security audit for credentials and logs
10.5 Deployment checklist
10.6 Documentation update and handover

## 3. Task Breakdown Prioritas MVP
### Prioritas 1 (Must Have)
- Setup project base
- User profile and auth
- Local posts
- Payment gateway abstraction
- RSS and custom API connector
- Database schema for core tables
- Smoke test and validation

### Prioritas 2 (Should Have)
- Atom connector
- Timeline normalization
- Admin settings page
- Integration queue
- Webhook handling

### Prioritas 3 (Nice to Have)
- OAuth connectors
- Fitur lanjutan toko online pribadi
- Federated commerce end-to-end (order lintas node) — keunggulan utama
- Plugin marketplace

## 4. Estimasi Sumber Daya
- 1 Product owner / business analyst
- 1 Backend developer
- 1 Frontend developer
- 1 QA / tester
- 1 DevOps / deployment support (opsional)

## 5. Deliverables
- BRD
- PRD
- WBS task plan
- MVP codebase
- Schema SQL
- Test script and smoke validation
- Documentation for setup and architecture

## 6. Catatan
WBS ini bersifat iteratif dan dapat diperluas sesuai dengan prioritas pengembangan. Fokus utama pada fase awal adalah membangun core node, payment abstraction, dan external connection capability tanpa mengunci pada provider tunggal.
