'use strict';

// SureHelp events a Zap can start from (WebhookEndpoint::EVENTS on the server), with a noun for Zapier.
module.exports = [
  { event: 'appointment.booked', key: 'appointment_booked', noun: 'Appointment', label: 'Appointment Booked', description: 'Triggers when SureHelp books a job: by a receptionist, the AI or online.' },
  { event: 'appointment.rescheduled', key: 'appointment_rescheduled', noun: 'Appointment', label: 'Appointment Moved', description: 'Triggers when an appointment moves to another time.' },
  { event: 'appointment.completed', key: 'appointment_completed', noun: 'Appointment', label: 'Appointment Completed', description: 'Triggers when an appointment is marked done.' },
  { event: 'appointment.no_show', key: 'appointment_no_show', noun: 'Appointment', label: 'Customer Didn\'t Show Up', description: 'Triggers when an appointment is marked as a no-show.' },
  { event: 'appointment.cancelled', key: 'appointment_cancelled', noun: 'Appointment', label: 'Appointment Cancelled', description: 'Triggers when an appointment is cancelled.' },
  { event: 'call.logged', key: 'call_logged', noun: 'Call', label: 'Call Answered', description: 'Triggers when a receptionist answers a call and logs it, with the reason and the outcome.' },
  { event: 'call.missed', key: 'call_missed', noun: 'Call', label: 'Call Missed', description: 'Triggers when a call to your SureHelp number isn\'t answered.' },
  { event: 'conversation.started', key: 'conversation_started', noun: 'Conversation', label: 'New Conversation', description: 'Triggers on the first message of a new chat, text or social conversation.' },
  { event: 'customer.created', key: 'customer_created', noun: 'Customer', label: 'New Customer or Lead', description: 'Triggers when a new customer or lead is added.' },
  { event: 'escalation.raised', key: 'escalation_raised', noun: 'Escalation', label: 'Urgent Matter Raised', description: 'Triggers when a receptionist raises an escalation for you.' },
  { event: 'task.overdue', key: 'task_overdue', noun: 'Task', label: 'Task Overdue', description: 'Triggers when a follow-up task becomes overdue.' },
  { event: 'review.received', key: 'review_received', noun: 'Review', label: 'New Google Review', description: 'Triggers when a new Google review comes in.' },
  { event: 'survey.answered', key: 'survey_answered', noun: 'Survey', label: 'Satisfaction Survey Answered', description: 'Triggers when a customer answers the after-call survey.' },
  { event: 'payment.paid', key: 'payment_paid', noun: 'Payment', label: 'Customer Payment Received', description: 'Triggers when a customer pays a deposit or a pay-by-text link.' },
];
