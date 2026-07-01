# SIANS - Cloud Infrastructure Automation Platform

> Cloud-native platform developed to automate the provisioning and lifecycle management of Minecraft hosting infrastructure.

---

# Overview

SIANS is a personal Cloud & Automation Engineering project focused on designing and implementing an infrastructure management platform capable of automatically provisioning, managing and monitoring Minecraft game servers.

The objective was **not** to build a traditional hosting website, but to reproduce the architecture and operational workflows used by modern Infrastructure Providers.

The platform integrates infrastructure automation, virtualization, payment processing and server lifecycle management into a single application.

---

# Project Goals

- Automate infrastructure provisioning
- Eliminate manual deployment tasks
- Manage complete server lifecycle
- Provide a customer self-service dashboard
- Integrate infrastructure APIs
- Demonstrate Cloud Engineering competencies through a real-world project

---

# Main Features

## Infrastructure Automation

- Automatic virtual server provisioning
- Infrastructure orchestration
- Remote VM management
- Automated deployment workflow

---

## Server Lifecycle Management

- Create new Minecraft servers
- Start / Stop / Restart servers
- Real-time status monitoring
- Console command execution
- Log visualization
- Server configuration updates

---

## Customer Portal

- Authentication system
- User dashboard
- Server management interface
- Password management
- Billing management
- User profile

---

## Payment Integration

Integrated payment workflow using Stripe.

Features include:

- Checkout session creation
- Subscription-ready architecture
- Billing management
- Payment workflow integration

---

## Infrastructure Integration

The platform communicates with external infrastructure through APIs including:

- Proxmox VE
- Cloudflare
- Stripe

This project focuses on API orchestration rather than manual infrastructure administration.

---

# Technical Stack

## Backend

- PHP
- Composer
- REST API integrations
- AJAX

---

## Infrastructure

- Proxmox VE
- Virtual Machines
- Cloudflare
- Linux

---

## Security

Security was considered during development through features including:

- CSRF protection
- Session management
- Authentication middleware
- HTTPS enforcement
- Environment variables
- Password hashing

---

# Architecture

```
                 User
                   │
                   ▼
         Web Management Portal
                   │
        ┌──────────┴──────────┐
        ▼                     ▼
 Authentication        Billing System
        │                     │
        ▼                     ▼
 Infrastructure API      Stripe API
        │
        ▼
    Proxmox Cluster
        │
        ▼
 Minecraft Servers
```

---

# Skills Demonstrated

This project demonstrates practical experience with:

- Cloud Infrastructure
- Infrastructure Automation
- API Integration
- Infrastructure Provisioning
- Virtualization Management
- Backend Development
- Infrastructure Security
- Payment Gateway Integration
- Server Lifecycle Management
- Session Management
- Software Architecture

---

# Why this project?

Many hosting providers rely on automated provisioning systems to reduce operational overhead.

SIANS was developed to explore the engineering challenges involved in:

- infrastructure orchestration
- virtualization management
- customer self-service
- automation workflows
- cloud platform development

The project simulates several concepts commonly found in commercial Infrastructure-as-a-Service platforms.

---

# Disclaimer

This repository is intended as a technical portfolio project demonstrating Cloud & Automation Engineering skills.

The primary objective is educational and architectural, showcasing the design and implementation of infrastructure automation workflows rather than replicating the business model of any hosting provider.

---

# Future Improvements

- Kubernetes support
- Docker-based deployments
- Terraform integration
- Ansible automation
- Multi-node orchestration
- Metrics and monitoring
- Prometheus integration
- Grafana dashboards
- CI/CD pipeline
- Infrastructure as Code
- Role-Based Access Control (RBAC)
- High Availability support

---

# Author

**Diego Nuzzolo**

Cloud & Automation Engineer

Areas of interest:

- Cloud Infrastructure
- Infrastructure Automation
- Virtualization
- Linux
- DevOps Tooling
- API Integration
- Infrastructure Engineering
