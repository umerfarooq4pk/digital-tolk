Let's start by analyzing the refactored code and discussing its strengths and weaknesses:

Strengths:

Readability: The refactored code is generally readable, with clear variable names and comments explaining the purpose of certain sections.
Modularization: The code is divided into separate functions, each responsible for a specific task. This promotes reusability and maintainability.
Database Abstraction: The use of Eloquent ORM and Query Builder helps abstract database operations, making the code more database-agnostic and easier to maintain.
Weaknesses:

Complexity: The bookingExpireNoAccepted function is quite complex and contains a lot of conditional logic, which makes it harder to understand and maintain.
Violation of Single Responsibility Principle (SRP): The function bookingExpireNoAccepted seems to handle multiple concerns like fetching data, applying filters, and constructing queries, violating the SRP.
Hardcoded Conditions: Some conditions and values are hardcoded directly into the function, making it less flexible and harder to adapt to changes in requirements.
Database Queries Within the Function: The function directly constructs and executes database queries, which can make it difficult to test and reuse the logic.
To improve this code, we can consider the following approaches:

Separation of Concerns: Break down the bookingExpireNoAccepted function into smaller, more focused functions, each responsible for a single aspect such as fetching data, applying filters, and constructing queries. This adheres to the Single Responsibility Principle and makes the code easier to understand and maintain.

Use of Repository Pattern: Implement a repository pattern to abstract database operations away from the controller. This would centralize database operations, making them easier to manage, test, and swap out with different implementations (e.g., different database engines).

Dependency Injection: Instead of directly accessing dependencies like Auth and DB within the function, consider injecting them as dependencies. This makes the code more testable and decouples it from specific implementations, improving flexibility and maintainability.

Use of Models and Relationships: Leverage Eloquent models and relationships to encapsulate database queries and establish associations between entities. This promotes cleaner code and reduces the need for manual query construction.

Validation and Sanitization: Implement input validation and sanitization to ensure that the data received by the function is valid and safe to use. This helps prevent security vulnerabilities and ensures the integrity of the system.

Overall, while the refactored code demonstrates some good practices such as readability and database abstraction, there is room for improvement in terms of code structure, modularity, and adherence to SOLID principles. By applying the suggested improvements, we can create code that is more maintainable, testable, and scalable.