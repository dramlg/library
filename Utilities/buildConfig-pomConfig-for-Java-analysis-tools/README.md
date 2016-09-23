# Ant-build sample

runs the five tools Findbugs [1], Checkstyle [2], PMD [3], JUnit [4] and Cobertura [5]. 
Additionally it creates and collects the native HTML reports of each tool.
Ant-builds have the big advantage over Maven-builds that you have everything under control and a minimal foot print of the build result. 
This justifies the overhead of writing the build.xml file. 

References

- [1] 	FindBugs™ - Is a static code analysis tool that analyses Java byte code and detects a wide range of problems.
- [2] 	Checkstyle - Is a development tool to help programmers write Java code that adheres to a coding standard.
- [3] 	PMD - Scans source code and looks for potential problems possible bugs, unused and sub-optimal code and over-complicated expressions; 
- [4] 	JUnit - Is a unit testing framework for the Java programming language.
- [5] 	Cobertura - Is a Java code coverage analysis tool. You can use it to determine what percentage of your source code is exercised by your unit tests.
- [6]	Hudson - Is an extensible Continuous Integration Server.

## pom.xml

maven config file for findbugs, checkstyle and PMD
