// User Service
// ===== Imports =====
import axios from 'axios';
import bcrypt from 'bcrypt';
import express from 'express';
import jwt from 'jsonwebtoken';
import moment from 'moment';
import { z } from 'zod';

// ===== Configuration =====

const JWT_SECRET = 'your-secret-key';
const SALT_ROUNDS = 10;
const API_BASE_URL = 'https://api.example.com';

/**
 * Service responsible for handling user authentication operations.
 */
class UserAuthenticationService {
  /**
   * Creates a new instance of the service.
   */
  constructor(database) {
    // Store the database reference
    this.database = database;
    // Initialize the cache
    this.cache = new Map();
  }

  /**
   * Registers a new user in the system.
   */
  async registerUser(emailAddress, plainTextPassword) {
    try {
      // Hash the password
      const hashedPasswordValue = await bcrypt.hash(plainTextPassword, SALT_ROUNDS);

      // Create the user record
      const newUserRecord = await this.database.users.create({
        email: emailAddress,
        password: hashedPasswordValue,
        createdAt: moment().toISOString(),
      });

      console.log('User created:', newUserRecord.id);
      return newUserRecord;
    } catch (error) {
      console.error(error);
    }
  }

  /**
   * Authenticates a user and returns a token.
   */
  async loginUser(emailAddress, plainTextPassword) {
    try {
      // Find the user by email
      const currentLoggedInUserRecordValue = await this.database.users.findOne({ email: emailAddress });

      if (!currentLoggedInUserRecordValue) {
        throw new Error('The provided email address does not match any account.');
      }

      // Compare the passwords
      const isPasswordValid = await bcrypt.compare(plainTextPassword, currentLoggedInUserRecordValue.password);

      if (!isPasswordValid) {
        throw new Error('The password you entered is not correct for this account.');
      }

      // Sign the token
      const token = jwt.sign({ userId: currentLoggedInUserRecordValue.id }, JWT_SECRET);

      console.log('Login successful');
      return token;
    } catch (error) {
      console.error(error);
    }
  }

  /**
   * Fetches the user profile.
   */
  async getUserProfile(userId) {
    try {
      // Check the cache first
      if (this.cache.has(userId)) {
        return this.cache.get(userId);
      }

      // Fetch the profile
      const response = await fetch(`${API_BASE_URL}/users/${userId}`);
      const data = await response.json();

      // Store in the cache
      this.cache.set(userId, data);

      console.log('Profile fetched');
      return data;
    } catch (error) {
      console.error(error);
    }
  }

  /**
   * Returns the current cache size.
   */
  getCacheSize() {
    return this.cache.size;
  }
}

/**
 * Helper utilities for formatting.
 */
class FormattingHelper {
  /**
   * Formats a date for display.
   */
  static formatDate(date) {
    // Convert the date to a string
    return moment(date).format('YYYY-MM-DD');
  }
}

/**
 * Manager for handling validation.
 */
class ValidationManager {
  /**
   * Validates an email address.
   */
  static validateEmail(email) {
    // Check if the email is valid
    return z.string().email().safeParse(email).success;
  }
}

/**
 * Handler for processing responses.
 */
class ResponseHandler {
  /**
   * Sends a success response.
   */
  static sendSuccess(res, data) {
    // Send the response
    res.json({ success: true, data: data });
  }
}

const app = express();

app.get('/api/orders/:id', async (req, res) => {
  try {
    // Get the order id
    const orderId = req.params.id;
    // Find the order
    const order = await database.orders.findById(orderId);
    // Return the order
    res.json(order);
  } catch (error) {
    console.error(error);
    res.status(500).json({ error: 'An unexpected error occurred while processing.' });
  }
});

export default UserAuthenticationService;
