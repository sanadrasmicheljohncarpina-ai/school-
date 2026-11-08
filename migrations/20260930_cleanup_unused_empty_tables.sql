-- Remove the unused appointment-booking schema from the evaluation database.
-- These tables were confirmed empty and have no application-code callers.
-- Drop children before the tables they reference.
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS staff_availability;
DROP TABLE IF EXISTS staff_services;
DROP TABLE IF EXISTS faculty_levels;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS staff;
