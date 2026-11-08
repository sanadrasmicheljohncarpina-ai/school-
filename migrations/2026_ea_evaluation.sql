-- EA Evaluation feature migration
-- Principal, Dean and Non-Teaching Staff use eval_type='ea'.
-- Non-Teaching Staff means a Staff user with no user_year_levels assignment.

ALTER TABLE evaluation_tracker
    MODIFY eval_type VARCHAR(30) NOT NULL DEFAULT 'student';

ALTER TABLE evaluation_tracker
    ADD INDEX idx_ea_evaluation_lookup (evaluator_id, target_user_id, period_id, eval_type);
