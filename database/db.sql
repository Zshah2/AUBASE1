USE aubase;

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS Bid;
DROP TABLE IF EXISTS Item_Category;
DROP TABLE IF EXISTS Auction;
DROP TABLE IF EXISTS Item;
DROP TABLE IF EXISTS Category;
DROP TABLE IF EXISTS User;
DROP TABLE IF EXISTS CurrentTime;
DROP TABLE IF EXISTS BankInfo;
DROP TABLE IF EXISTS `Order`;
DROP TABLE IF EXISTS CreditCard;
DROP TABLE IF EXISTS Review;
DROP TABLE IF EXISTS ShippingOption;

SET FOREIGN_KEY_CHECKS=1;

-- Recreate with BIGINT for item_id
CREATE TABLE User (
                      user_id VARCHAR(50) PRIMARY KEY,
                      username VARCHAR(100) UNIQUE,
                      email VARCHAR(100) UNIQUE,
                      first_name VARCHAR(50),
                      last_name VARCHAR(50),
                      address VARCHAR(255),
                      phone VARCHAR(20),
                      password_hash VARCHAR(255) DEFAULT NULL,
                      email_verified TINYINT(1) NOT NULL DEFAULT 1,
                      verify_token VARCHAR(64) DEFAULT NULL,
                      verify_expires DATETIME DEFAULT NULL,
                      password_reset_token VARCHAR(64) DEFAULT NULL,
                      password_reset_expires DATETIME DEFAULT NULL,
                      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                      deleted_at DATETIME DEFAULT NULL,
                      rating INT DEFAULT 0
);

CREATE TABLE Item (
                      item_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                      name VARCHAR(255) NOT NULL,
                      description TEXT,
                      location VARCHAR(255),
                      country VARCHAR(100),
                      seller_id VARCHAR(50) NOT NULL,
                      FOREIGN KEY (seller_id) REFERENCES User(user_id)
);

CREATE TABLE Category (
                          category_id INT AUTO_INCREMENT PRIMARY KEY,
                          name VARCHAR(100) UNIQUE NOT NULL,
                          description TEXT
);

CREATE TABLE Item_Category (
                               item_id BIGINT NOT NULL,
                               category_id INT NOT NULL,
                               PRIMARY KEY (item_id, category_id),
                               FOREIGN KEY (item_id) REFERENCES Item(item_id),
                               FOREIGN KEY (category_id) REFERENCES Category(category_id)
);

CREATE TABLE Auction (
                         auction_id INT AUTO_INCREMENT PRIMARY KEY,
                         item_id BIGINT UNIQUE NOT NULL,
                         start_time DATETIME NOT NULL,
                         end_time DATETIME NOT NULL,
                         starting_price DECIMAL(10,2) NOT NULL,
                         current_price DECIMAL(10,2) NOT NULL,
                         buy_price DECIMAL(10,2) DEFAULT NULL,
                         num_bids INT DEFAULT 0,
                         FOREIGN KEY (item_id) REFERENCES Item(item_id),
                         CONSTRAINT chk_time CHECK (end_time > start_time)
);

CREATE TABLE Bid (
                     bid_id INT AUTO_INCREMENT PRIMARY KEY,
                     auction_id INT NOT NULL,
                     bidder_id VARCHAR(50) NOT NULL,
                     amount DECIMAL(10,2) NOT NULL,
                     bid_time DATETIME NOT NULL,
                     FOREIGN KEY (auction_id) REFERENCES Auction(auction_id),
                     FOREIGN KEY (bidder_id) REFERENCES User(user_id)
);

CREATE TABLE CreditCard (
                            card_id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id VARCHAR(50) NOT NULL,
                            card_number VARCHAR(20) NOT NULL,
                            expiration_date DATE NOT NULL,
                            ccv VARCHAR(4) NOT NULL,
                            cardholder_name VARCHAR(100) NOT NULL,
                            billing_address VARCHAR(255) NOT NULL,
                            FOREIGN KEY (user_id) REFERENCES User(user_id)
);

CREATE TABLE Review (
                        review_id INT AUTO_INCREMENT PRIMARY KEY,
                        reviewer_id VARCHAR(50) NOT NULL,
                        seller_id VARCHAR(50) NOT NULL,
                        auction_id INT NOT NULL,
                        rating INT CHECK (rating BETWEEN 1 AND 5),
                        feedback TEXT,
                        FOREIGN KEY (reviewer_id) REFERENCES User(user_id),
                        FOREIGN KEY (seller_id) REFERENCES User(user_id),
                        FOREIGN KEY (auction_id) REFERENCES Auction(auction_id)
);

CREATE TABLE ShippingOption (
                                shipping_option_id INT AUTO_INCREMENT PRIMARY KEY,
                                auction_id INT NOT NULL,
                                method VARCHAR(100) NOT NULL,
                                price DECIMAL(10,2) NOT NULL,
                                estimated_days INT,
                                is_pickup BOOLEAN DEFAULT FALSE,
                                FOREIGN KEY (auction_id) REFERENCES Auction(auction_id)
);

CREATE TABLE BankInfo (
                          bank_id INT AUTO_INCREMENT PRIMARY KEY,
                          seller_id VARCHAR(50) UNIQUE NOT NULL,
                          bank_name VARCHAR(100) NOT NULL,
                          routing_number VARCHAR(20) NOT NULL,
                          account_number VARCHAR(20) NOT NULL,
                          FOREIGN KEY (seller_id) REFERENCES User(user_id)
);

CREATE TABLE `Order` (
                         order_id INT AUTO_INCREMENT PRIMARY KEY,
                         auction_id INT UNIQUE NOT NULL,
                         buyer_id VARCHAR(50) NOT NULL,
                         card_id INT NOT NULL,
                         shipping_option_id INT NOT NULL,
                         bid_amount DECIMAL(10,2) NOT NULL,
                         shipping_cost DECIMAL(10,2) NOT NULL,
                         payment_status ENUM('pending','paid','failed') DEFAULT 'pending',
                         payment_time DATETIME,
                         delivery_confirmed BOOLEAN DEFAULT FALSE,
                         tracking_number VARCHAR(100),
                         ship_to_name VARCHAR(150) DEFAULT NULL,
                         ship_to_line1 VARCHAR(255) DEFAULT NULL,
                         ship_to_line2 VARCHAR(255) DEFAULT NULL,
                         ship_to_city VARCHAR(100) DEFAULT NULL,
                         ship_to_region VARCHAR(100) DEFAULT NULL,
                         ship_to_postal VARCHAR(32) DEFAULT NULL,
                         ship_to_country VARCHAR(100) DEFAULT NULL,
                         FOREIGN KEY (auction_id) REFERENCES Auction(auction_id),
                         FOREIGN KEY (buyer_id) REFERENCES User(user_id),
                         FOREIGN KEY (card_id) REFERENCES CreditCard(card_id),
                         FOREIGN KEY (shipping_option_id) REFERENCES ShippingOption(shipping_option_id)
);

CREATE TABLE CurrentTime (
                             id INT PRIMARY KEY DEFAULT 1,
                             system_time DATETIME NOT NULL
);

INSERT INTO CurrentTime (id, system_time)
VALUES (1, NOW())
ON DUPLICATE KEY UPDATE system_time = VALUES(system_time);



